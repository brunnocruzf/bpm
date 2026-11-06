<?php

namespace App\Backup;

use App\Support\Masker;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Connection;
use Illuminate\Process\InvokedProcess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Ferramentas externas do backup: pg_dump/pg_restore (PostgreSQL 17), zstd e age. Tudo o que depende do servidor de
 * banco de verdade fica aqui; os testes substituem esta classe, e o caminho real é exercido em bpm-e2e (deploy/docker/tests).
 */
class BackupTools
{
    private const PROBE = 'backup_probe';

    /**
     * Abre o dump de bpm_app e, com o snapshot dele já existindo, o de flowable. A ordem importa: se o engine ficasse mais
     * antigo que a aplicação, o reconciliador cancelaria tarefas; o contrário ele conserta. $opened solta a pausa do outbox.
     */
    public function dumpDatabases(string $appFile, string $engineFile, Closure $opened): void
    {
        $app = $this->startDump($this->appDatabase(), $appFile);
        $this->waitForSnapshot($this->appDatabase(), $app);
        $engine = $this->startDump($this->engineDatabase(), $engineFile);
        $this->waitForSnapshot($this->engineDatabase(), $engine);
        $opened();
        foreach (['bpm_app' => $app, 'flowable' => $engine] as $name => $process) {
            $this->assertOk($process->wait(), "pg_dump de $name");
        }
    }

    public function dumpGlobals(string $file): void
    {
        $result = $this->pg()->run([$this->bin('pg_dumpall'), ...$this->connection(), '--globals-only', '--no-role-passwords', '-f', $file]);
        $this->assertOk($result, 'pg_dumpall');
    }

    /** Lê o índice do dump: arquivo cortado ou corrompido falha aqui, antes de virar backup. */
    public function checkDump(string $file): void
    {
        $this->assertOk(Process::forever()->run([$this->bin('pg_restore'), '--list', $file]), 'conferência do dump');
    }

    public function encrypt(string $source, string $target, string $recipient): void
    {
        $this->assertOk($this->shell('zstd -q -3 -T0 -c '.escapeshellarg($source).' | age -r '.escapeshellarg($recipient).' -o '.escapeshellarg($target)), 'criptografia');
    }

    public function decrypt(string $source, string $target, string $identityFile): void
    {
        $result = $this->shell('age -d -i '.escapeshellarg($identityFile).' '.escapeshellarg($source).' | zstd -q -d -c > '.escapeshellarg($target));
        if ($result->failed()) {
            throw new BackupFailure('decrypt_failed', 'Não foi possível abrir o backup: a chave não confere ou o arquivo está corrompido.');
        }
    }

    /** @return resource entrada de um zstd | age: o que for escrito nela vai criptografado para $target */
    public function encryptingWriter(string $target, string $recipient)
    {
        return $this->pipe('set -o pipefail; zstd -q -3 -T0 | age -r '.escapeshellarg($recipient).' -o '.escapeshellarg($target), 'w');
    }

    /** @return resource saída de um age | zstd: o conteúdo aberto de $source */
    public function decryptingReader(string $source, string $identityFile)
    {
        return $this->pipe('set -o pipefail; age -d -i '.escapeshellarg($identityFile).' '.escapeshellarg($source).' | zstd -q -d', 'r');
    }

    /** @param resource $pipe */
    public function closePipe($pipe, string $what): void
    {
        if (pclose($pipe) !== 0) {
            throw new BackupFailure('tool_failed', "Falha em $what.");
        }
    }

    /** Empacota .env, chave do cofre e deploy/docker. Só existe no nível Aplicação inteira. */
    public function packConfig(string $target, string $recipient): void
    {
        $env = (string) config('bpm.backup.env_file');
        $deploy = (string) config('bpm.backup.deploy_dir');
        $secrets = rtrim((string) config('bpm.secrets.files_dir', '/run/secrets'), '/');
        foreach ([$env, $deploy, "$secrets/bpm_secrets_key"] as $path) {
            if (! is_readable($path)) {
                throw new BackupFailure('config_unreadable', "O agente não consegue ler $path. Confira os volumes do serviço backup-agent.");
            }
        }
        $command = sprintf('tar -cf - -C %s %s -C %s %s -C %s bpm_secrets_key | zstd -q -3 -T0 | age -r %s -o %s',
            escapeshellarg(dirname($env)), escapeshellarg(basename($env)), escapeshellarg(dirname($deploy)), escapeshellarg(basename($deploy)), escapeshellarg($secrets),
            escapeshellarg($recipient), escapeshellarg($target));
        $this->assertOk($this->shell($command), 'pacote de configuração');
    }

    public function appDatabase(): string
    {
        return (string) config('database.connections.pgsql.database');
    }

    public function engineDatabase(): string
    {
        return (string) config('bpm.backup.engine_database');
    }

    public function appOwner(): string
    {
        return (string) config('database.connections.pgsql.username');
    }

    public function engineOwner(): string
    {
        return $this->engineDatabase();
    }

    public function databaseExists(string $name): bool
    {
        return DB::connection('backup_admin')->selectOne('select 1 as found from pg_database where datname = ?', [$name]) !== null;
    }

    public function createDatabase(string $name, string $owner): void
    {
        $this->assertName($name);
        $this->assertName($owner);
        $admin = DB::connection('backup_admin');
        $admin->statement("CREATE DATABASE \"$name\" OWNER \"$owner\"");
        $admin->statement("REVOKE ALL ON DATABASE \"$name\" FROM PUBLIC");
    }

    public function dropDatabase(string $name): void
    {
        $this->assertName($name);
        DB::connection('backup_admin')->statement("DROP DATABASE IF EXISTS \"$name\" WITH (FORCE)");
    }

    public function restoreDump(string $file, string $database): void
    {
        $this->assertName($database);
        $result = $this->pg()->run([$this->bin('pg_restore'), ...$this->connection(), '-d', $database, '--exit-on-error', $file]);
        $this->assertOk($result, "carga de $database");
    }

    /**
     * Troca os nomes em uma transação: ou todos mudam, ou nenhum. Encerra as conexões que sobrarem antes, porque banco com
     * conexão aberta não pode ser renomeado.
     *
     * @param  array<string,string>  $renames  nome atual => nome novo, na ordem
     */
    public function renameDatabases(array $renames): void
    {
        DB::purge('pgsql');
        $admin = DB::connection('backup_admin');
        foreach ($renames as $from => $to) {
            $this->assertName($from);
            $this->assertName($to);
            $admin->select('select pg_terminate_backend(pid) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()', [$from]);
        }
        $admin->transaction(function () use ($admin, $renames) {
            foreach ($renames as $from => $to) {
                $admin->statement("ALTER DATABASE \"$from\" RENAME TO \"$to\"");
            }
        });
    }

    /** @return array{tables:int, rows:array<string,int>} contagens para comparar o banco carregado */
    public function databaseStats(string $database, array $tables = []): array
    {
        $probe = $this->probe($database);
        $total = (int) $probe->selectOne("select count(*) as total from information_schema.tables where table_schema = 'public'")->total;
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = (int) $probe->selectOne("select count(*) as total from \"$table\"")->total;
        }
        DB::purge(self::PROBE);

        return ['tables' => $total, 'rows' => $rows];
    }

    /**
     * Eventos de auditoria de um banco que saiu de uso, a partir de uma data.
     *
     * @return list<array<string,mixed>>
     */
    public function auditEventsSince(string $database, string $since): array
    {
        $events = $this->probe($database)->select('select * from audit_event where occurred_at > ? order by occurred_at, id', [$since]);
        DB::purge(self::PROBE);

        return array_map(fn ($row) => (array) $row, $events);
    }

    public function databaseBytes(): int
    {
        $row = DB::connection('backup_admin')->selectOne('select coalesce(sum(pg_database_size(datname)), 0) as total from pg_database where datname in (?, ?)', [$this->appDatabase(), $this->engineDatabase()]);

        return (int) $row->total;
    }

    public function serverVersion(): string
    {
        return (string) DB::connection('backup_admin')->selectOne('show server_version')->server_version;
    }

    public function clientVersion(): string
    {
        return trim(Process::run([$this->bin('pg_dump'), '--version'])->output());
    }

    public function lastMigration(): ?string
    {
        return DB::table('migrations')->orderByDesc('id')->value('migration');
    }

    private function startDump(string $database, string $file): InvokedProcess
    {
        return $this->pg(['PGAPPNAME' => "bpm-backup-$database"])->start([$this->bin('pg_dump'), ...$this->connection(), '-Fc', '-Z0', '-f', $file, $database]);
    }

    /** O snapshot existe quando a transação do pg_dump tem xmin. Dump curto pode terminar antes de ser visto. */
    private function waitForSnapshot(string $database, InvokedProcess $process): void
    {
        $admin = DB::connection('backup_admin');
        $deadline = microtime(true) + 60;
        while ($process->running() && microtime(true) < $deadline) {
            if ($admin->selectOne('select 1 as found from pg_stat_activity where application_name = ? and backend_xmin is not null', ["bpm-backup-$database"]) !== null) {
                return;
            }
            usleep(50_000);
        }
    }

    private function probe(string $database): Connection
    {
        config(['database.connections.'.self::PROBE => ['database' => $database] + (array) config('database.connections.backup_admin')]);
        DB::purge(self::PROBE);

        return DB::connection(self::PROBE);
    }

    private function bin(string $tool): string
    {
        return rtrim((string) config('bpm.backup.pg_bin'), '/').'/'.$tool;
    }

    /** @return list<string> */
    private function connection(): array
    {
        $c = (array) config('database.connections.backup_admin');

        return ['-h', (string) $c['host'], '-p', (string) $c['port'], '-U', (string) $c['username']];
    }

    /** @param array<string,string> $env */
    private function pg(array $env = []): PendingProcess
    {
        return Process::forever()->env(['PGPASSWORD' => (string) config('database.connections.backup_admin.password'), ...$env]);
    }

    private function shell(string $command): ProcessResult
    {
        return Process::forever()->run(['sh', '-c', 'set -o pipefail; '.$command]);
    }

    /** @return resource */
    private function pipe(string $command, string $mode)
    {
        $pipe = popen($command, $mode);
        if ($pipe === false) {
            throw new BackupFailure('tool_failed', 'Não foi possível iniciar as ferramentas de backup.');
        }

        return $pipe;
    }

    private function assertOk(ProcessResult $result, string $what): void
    {
        if ($result->failed()) {
            throw new BackupFailure('tool_failed', "Falha em $what: ".Masker::error(mb_substr(trim($result->errorOutput()), 0, 300)));
        }
    }

    private function assertName(string $name): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new BackupFailure('invalid_name', 'Nome de banco inválido.');
        }
    }
}
