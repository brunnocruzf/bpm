<?php

namespace App\Backup;

use App\Engine\FlowableRestClient;
use App\Models\BackupCatalogEntry;
use App\Settings\SettingsRepository;
use App\Settings\Types\BackupSettings;
use App\Storage\Azure\AzureBlobException;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Gera um backup (ADR-0021): dumps dos dois bancos, arquivos da plataforma e, no nível Aplicação inteira, configuração e
 * imagens. Tudo sai criptografado com a chave pública; o arquivo aberto só existe no diretório de trabalho. O manifesto é
 * gravado por último: backup sem manifesto não existe.
 */
class BackupRunner
{
    private const COUNTED_TABLES = ['users', 'process_instance', 'task_projection', 'attachment', 'audit_event'];

    public function __construct(
        private readonly BackupTools $tools,
        private readonly BackupStorage $storage,
        private readonly BackupCatalog $catalog,
        private readonly PlatformFiles $files,
        private readonly DockerGate $docker,
        private readonly RuntimeState $state,
        private readonly SettingsRepository $settings,
        private readonly FlowableRestClient $engine,
    ) {}

    /**
     * @param  array{scope:string, azure:bool, origin:string, scheduleId?:?string, verify?:bool, jobId?:?string, prune?:bool}  $options
     * @param  Closure(string, int): void  $progress
     * @return array{entry:BackupCatalogEntry, azureError:?string}
     */
    public function run(array $options, Closure $progress): array
    {
        $recipient = (string) ($this->settings->value(app(BackupSettings::class))['recoveryKey']['publicKey'] ?? '');
        if ($recipient === '') {
            throw new BackupFailure('recovery_key_missing', 'Gere e confirme a chave de recuperação antes de fazer backups.');
        }
        $scope = $options['scope'];
        $id = BackupPaths::newId();
        $staging = $this->storage->stagingDir($id);
        try {
            $progress('preparing', 2);
            $this->assertSpace($scope);
            $manifest = $this->baseManifest($id, $options, $recipient);

            $progress('database', 10);
            $app = "$staging/bpm_app.dump";
            $engine = "$staging/flowable.dump";
            $this->dumpBoth($app, $engine);
            $this->tools->dumpGlobals("$staging/globals.sql");
            $this->tools->checkDump($app);
            $this->tools->checkDump($engine);

            if ($options['verify'] ?? false) {
                $progress('verifying', 25);
                $manifest['verification'] = $this->verifyDumps($app, $engine, 'created');
            }

            $progress('encrypting', 35);
            foreach (['bpm_app.dump' => 'database', 'flowable.dump' => 'database', 'globals.sql' => 'globals'] as $name => $kind) {
                $manifest['parts'][] = $this->encryptPart($staging, $name, $kind, $recipient);
            }

            if ($scope !== BackupSettings::DATABASE) {
                $progress('files', 50);
                $manifest['parts'][] = $this->archiveFiles($staging, $recipient);
            }
            if ($scope === BackupSettings::FULL) {
                $progress('config', 65);
                $manifest['parts'][] = $this->packConfig($staging, $recipient);
                $progress('images', 70);
                $manifest['images'] = $this->exportImages($staging, $recipient, $options['azure']);
            }

            $raw = (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            file_put_contents($staging.'/'.BackupPaths::MANIFEST, $raw);
            $final = $this->storage->local(BackupPaths::dir($id));
            $this->storage->ensureDir(dirname($final));
            rename($staging, $final);
        } catch (Throwable $e) {
            File::deleteDirectory($staging);
            throw $e;
        }

        $azureError = null;
        $inAzure = false;
        if ($options['azure'] && $this->storage->azureEnabled()) {
            $progress('azure', 85);
            try {
                $this->uploadToAzure($manifest);
                $inAzure = true;
            } catch (AzureBlobException|BackupFailure $e) {
                // o backup continua válido no servidor; a tela oferece reenviar
                $azureError = $e->getMessage();
            }
        }

        $progress('retention', 95);
        $entry = $this->catalog->register($manifest, $raw, true, $inAzure, $manifest['verification'] ?? null);
        if ($options['prune'] ?? true) {
            $this->catalog->prune();
        }

        return ['entry' => $entry, 'azureError' => $azureError];
    }

    /** Envia as partes e, por último, o manifesto: quem lista o Azure nunca vê um backup pela metade. */
    public function uploadToAzure(array $manifest): void
    {
        foreach ($manifest['images'] ?? [] as $image) {
            if (is_file($this->storage->local($image['file'])) && ! $this->storage->azureHas($image['file'])) {
                $this->storage->upload($image['file']);
            }
        }
        foreach ($manifest['parts'] as $part) {
            $this->storage->upload(BackupPaths::part($manifest['id'], $part['name']));
        }
        $this->storage->upload(BackupPaths::part($manifest['id'], BackupPaths::MANIFEST));
        $this->uploadRecoveryGuide();
    }

    /** O roteiro de recuperação fica junto dos backups: quem perdeu o servidor pode ter perdido o repositório também. */
    private function uploadRecoveryGuide(): void
    {
        foreach (['dr-restore.sh', 'LEIA-ME-RECUPERACAO.md'] as $name) {
            $file = rtrim((string) config('bpm.backup.deploy_dir'), '/').'/'.$name;
            if (is_readable($file)) {
                $this->storage->uploadFrom($file, $name);
            }
        }
    }

    /**
     * Carrega os dumps em bancos temporários e compara: prova que o backup restaura de verdade.
     *
     * @return array<string,mixed>
     */
    public function verifyDumps(string $appDump, string $engineDump, string $kind): array
    {
        $app = 'bpm_verify_app';
        $engine = 'bpm_verify_engine';
        try {
            $this->tools->dropDatabase($app);
            $this->tools->dropDatabase($engine);
            $this->tools->createDatabase($app, $this->tools->appOwner());
            $this->tools->createDatabase($engine, $this->tools->engineOwner());
            $this->tools->restoreDump($appDump, $app);
            $this->tools->restoreDump($engineDump, $engine);

            return ['kind' => $kind, 'ok' => true, 'at' => now('UTC')->toIso8601String(), 'app' => $this->tools->databaseStats($app, self::COUNTED_TABLES), 'engine' => $this->tools->databaseStats($engine)];
        } finally {
            $this->tools->dropDatabase($app);
            $this->tools->dropDatabase($engine);
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function baseManifest(string $id, array $options, string $recipient): array
    {
        return [
            'formatVersion' => 1, 'id' => $id, 'organizationKey' => BackupPaths::organization(), 'scope' => $options['scope'], 'origin' => $options['origin'],
            'scheduleId' => $options['scheduleId'] ?? null, 'jobId' => $options['jobId'] ?? null, 'createdAt' => now('UTC')->toIso8601String(), 'lastMigration' => $this->tools->lastMigration(),
            'postgres' => ['server' => $this->tools->serverVersion(), 'client' => $this->tools->clientVersion()], 'engineVersion' => $this->engine->version(),
            'encryption' => ['tool' => 'age', 'recipient' => $recipient, 'fingerprint' => RecoveryKey::fingerprint($recipient)],
            'parts' => [], 'images' => [],
        ];
    }

    /** O engine não recebe nada entre os dois snapshots, então nenhuma submissão fica de um lado só. */
    private function dumpBoth(string $app, string $engine): void
    {
        $this->state->write(['mode' => RuntimeState::PAUSE]);
        try {
            $this->tools->dumpDatabases($app, $engine, fn () => $this->state->write(['mode' => RuntimeState::NORMAL]));
        } finally {
            if ($this->state->mode() === RuntimeState::PAUSE) {
                $this->state->write(['mode' => RuntimeState::NORMAL]);
            }
        }
    }

    /** @return array{name:string, kind:string, bytes:int, sha256:string} */
    private function encryptPart(string $dir, string $name, string $kind, string $recipient): array
    {
        $target = "$name.zst.age";
        $this->tools->encrypt("$dir/$name", "$dir/$target", $recipient);
        @unlink("$dir/$name");

        return $this->describe($dir, $target, $kind);
    }

    /** @return array{name:string, kind:string, bytes:int, sha256:string, files:int} */
    private function archiveFiles(string $dir, string $recipient): array
    {
        $name = 'files.tar.zst.age';
        $writer = $this->tools->encryptingWriter("$dir/$name", $recipient);
        try {
            $stats = $this->files->archive($writer);
        } finally {
            $this->tools->closePipe($writer, 'pacote de arquivos');
        }

        return [...$this->describe($dir, $name, 'files'), 'files' => $stats['files']];
    }

    /** @return array{name:string, kind:string, bytes:int, sha256:string} */
    private function packConfig(string $dir, string $recipient): array
    {
        $name = 'config.tar.zst.age';
        $this->tools->packConfig("$dir/$name", $recipient);

        return $this->describe($dir, $name, 'config');
    }

    /**
     * Cada imagem vai uma vez por digest; backup seguinte só cita o arquivo.
     *
     * @return list<array<string,mixed>>
     */
    private function exportImages(string $staging, string $recipient, bool $azure): array
    {
        $useAzure = $azure && $this->storage->azureEnabled();
        // em ordem crescente: o keyBy fica com o último, e o manifesto mais novo descreve o arquivo que existe hoje
        $known = BackupCatalogEntry::where('scope', BackupSettings::FULL)->orderBy('backup_at')->get()->flatMap(fn ($e) => $e->manifest['images'] ?? [])->keyBy('id');
        $images = [];
        foreach ((array) config('bpm.backup.image_services') as $service) {
            $info = $this->docker->image((string) $service);
            if ($info === null) {
                continue;
            }
            $file = BackupPaths::image($info['image']);
            $local = $this->storage->local($file);
            $previous = $known->get($info['image']);
            if (! is_file($local) && ! ($previous && $useAzure && $this->storage->azureHas($file))) {
                $tar = "$staging/image.tar";
                $this->docker->exportImage($info['image'], $tar);
                $this->storage->ensureDir(dirname($local));
                $this->tools->encrypt($tar, "$local.tmp", $recipient);
                rename("$local.tmp", $local);
                @unlink($tar);
            }
            $facts = is_file($local) ? ['bytes' => (int) filesize($local), 'sha256' => (string) hash_file('sha256', $local)] : ['bytes' => (int) $previous['bytes'], 'sha256' => (string) $previous['sha256']];
            $images[] = ['service' => $service, 'ref' => $info['ref'], 'id' => $info['image'], 'file' => $file, ...$facts];
        }

        return $images;
    }

    /** @return array{name:string, kind:string, bytes:int, sha256:string} */
    private function describe(string $dir, string $name, string $kind): array
    {
        return ['name' => $name, 'kind' => $kind, 'bytes' => (int) filesize("$dir/$name"), 'sha256' => (string) hash_file('sha256', "$dir/$name")];
    }

    /** Dump aberto e criptografado convivem por um momento, e os arquivos entram em cima disso. */
    private function assertSpace(string $scope): void
    {
        $need = $this->tools->databaseBytes() * 2;
        if ($scope !== BackupSettings::DATABASE) {
            $need += (int) DB::table('attachment')->sum('size');
        }
        $free = disk_free_space($this->storage->root());
        if ($free !== false && $free < $need) {
            throw new BackupFailure('disk_full', 'Não há espaço em disco para este backup.');
        }
    }
}
