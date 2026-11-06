<?php

namespace Tests\Support;

use App\Backup\BackupFailure;
use App\Backup\BackupTools;
use Closure;

/**
 * Substitui pg_dump, zstd e age nos testes: os arquivos são reais, mas o conteúdo é o que foi escrito. "Bancos" são uma lista
 * em memória, o que permite conferir troca, reversão e limpeza. O caminho real é exercido em deploy/docker/tests.
 */
class FakeBackupTools extends BackupTools
{
    /** @var array<string,string> nome => origem do conteúdo */
    public array $databases = ['bpm_app' => 'em uso', 'flowable' => 'em uso'];

    /** @var list<string> */
    public array $calls = [];

    public ?string $failOn = null;

    public int $freeze = 0;

    public string $validIdentity = 'chave-certa';

    public function dumpDatabases(string $appFile, string $engineFile, Closure $opened): void
    {
        $this->calls[] = 'dump:bpm_app';
        file_put_contents($appFile, 'dump bpm_app');
        $this->calls[] = 'dump:flowable';
        file_put_contents($engineFile, 'dump flowable');
        $opened();
        $this->failWhen('dump');
    }

    public function dumpGlobals(string $file): void
    {
        file_put_contents($file, 'roles');
    }

    public function checkDump(string $file): void
    {
        $this->failWhen('check');
    }

    public function encrypt(string $source, string $target, string $recipient): void
    {
        file_put_contents($target, "$recipient\n".(string) file_get_contents($source));
    }

    public function decrypt(string $source, string $target, string $identityFile): void
    {
        $this->assertIdentity($identityFile);
        $lines = explode("\n", (string) file_get_contents($source), 2);
        file_put_contents($target, $lines[1] ?? '');
    }

    /** @return resource */
    public function encryptingWriter(string $target, string $recipient)
    {
        $file = fopen($target, 'wb');
        fwrite($file, "$recipient\n");

        return $file;
    }

    /** @return resource */
    public function decryptingReader(string $source, string $identityFile)
    {
        $this->assertIdentity($identityFile);
        $file = fopen($source, 'rb');
        fgets($file);

        return $file;
    }

    public function closePipe($pipe, string $what): void
    {
        fclose($pipe);
    }

    public function packConfig(string $target, string $recipient): void
    {
        file_put_contents($target, "$recipient\nconfig");
    }

    public function appDatabase(): string
    {
        return 'bpm_app';
    }

    public function engineDatabase(): string
    {
        return 'flowable';
    }

    public function appOwner(): string
    {
        return 'bpm_app';
    }

    public function engineOwner(): string
    {
        return 'flowable';
    }

    public function databaseExists(string $name): bool
    {
        return isset($this->databases[$name]);
    }

    public function createDatabase(string $name, string $owner): void
    {
        $this->calls[] = "create:$name";
        $this->databases[$name] = 'vazio';
    }

    public function dropDatabase(string $name): void
    {
        unset($this->databases[$name]);
    }

    public function restoreDump(string $file, string $database): void
    {
        $this->calls[] = "load:$database";
        $this->failWhen('load');
        $this->databases[$database] = 'restaurado';
    }

    public function renameDatabases(array $renames): void
    {
        $this->calls[] = 'rename';
        $this->failWhen('rename');
        foreach ($renames as $from => $to) {
            $this->databases[$to] = $this->databases[$from];
            unset($this->databases[$from]);
        }
    }

    public function databaseStats(string $database, array $tables = []): array
    {
        return ['tables' => 47, 'rows' => array_fill_keys($tables, 1)];
    }

    public function auditEventsSince(string $database, string $since): array
    {
        return [['id' => 'e1', 'action' => 'task.completed', 'occurred_at' => now()->toDateTimeString()]];
    }

    public function databaseBytes(): int
    {
        return 1024;
    }

    public function serverVersion(): string
    {
        return '17.6';
    }

    public function clientVersion(): string
    {
        return 'pg_dump (PostgreSQL) 17.11';
    }

    private function assertIdentity(string $file): void
    {
        if (trim((string) file_get_contents($file)) !== $this->validIdentity) {
            throw new BackupFailure('decrypt_failed', 'Não foi possível abrir o backup: a chave não confere ou o arquivo está corrompido.');
        }
    }

    private function failWhen(string $step): void
    {
        if ($this->failOn === $step) {
            throw new BackupFailure('tool_failed', "Falha simulada em $step.");
        }
    }
}
