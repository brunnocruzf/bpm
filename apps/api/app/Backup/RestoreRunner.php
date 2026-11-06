<?php

namespace App\Backup;

use App\Engine\FlowableRestClient;
use App\Models\AuthSession;
use App\Models\BackupCatalogEntry;
use App\Models\BackupJob;
use App\Models\EngineCommand;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Settings\Types\BackupSettings;
use App\Support\AuditLogger;
use App\Support\Masker;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Restaura ao lado e troca por renomeação (ADR-0021). A carga vai para bancos temporários com o sistema no ar, então uma
 * falha nela não toca nos dados em uso; só a troca precisa de manutenção. Os bancos antigos ficam até a liberação, e por
 * isso desfazer é a mesma troca ao contrário. O diário em runtime.json permite retomar se o agente cair no meio.
 */
class RestoreRunner
{
    private const PHASE_STOPPING = 'stopping';

    private const PHASE_SWAPPED = 'swapped';

    private const PHASE_SAFE = 'safe';

    private const PHASE_UNDOING = 'undoing';

    /** Três minutos: o Flowable leva cerca de 35 s para subir. */
    private const ENGINE_CHECKS = 60;

    public function __construct(
        private readonly BackupTools $tools,
        private readonly BackupStorage $storage,
        private readonly BackupCatalog $catalog,
        private readonly PlatformFiles $files,
        private readonly DockerGate $docker,
        private readonly RuntimeState $state,
        private readonly BackupRunner $backups,
        private readonly FlowableRestClient $engine,
    ) {}

    /** @param Closure(string, int): void $progress */
    public function restore(BackupJob $job, BackupCatalogEntry $entry, string $identity, Closure $progress): void
    {
        $work = $this->storage->stagingDir('restore-'.$job->id);
        $names = $this->names($job->id);
        try {
            $progress('validating', 2);
            $this->validate($entry);

            // sem retenção: ela poderia apagar o próprio backup que está sendo restaurado
            $progress('safety', 5);
            $this->backups->run(['scope' => BackupSettings::DATABASE, 'azure' => $this->storage->azureEnabled(), 'origin' => BackupCatalogEntry::PRE_RESTORE, 'prune' => false], fn () => null);

            $progress('fetching', 25);
            $parts = $this->fetch($entry->manifest, $work, fn (string $relative, string $target) => $this->obtain($entry, $relative, $target));
            $dumps = $this->openDumps($parts, $work, $identity);

            $progress('loading', 40);
            $this->load($dumps, $names);

            if (isset($parts['files.tar.zst.age'])) {
                $progress('files', 60);
                $this->restoreFiles($parts['files.tar.zst.age'], $identity);
            }
        } catch (Throwable $e) {
            $this->dropIncoming($names);
            File::deleteDirectory($work);
            throw $e;
        }
        File::deleteDirectory($work);
        $this->swapIn($job, $entry, $names, $progress);
    }

    /**
     * Ensaio: baixa, abre e carrega em bancos temporários, sem tocar nos dados em uso. Prova o backup e a chave.
     *
     * @param  Closure(string, int): void  $progress
     * @return array<string,mixed>
     */
    public function test(BackupCatalogEntry $entry, string $identity, Closure $progress): array
    {
        $work = $this->storage->stagingDir('test-'.$entry->id);
        try {
            $progress('fetching', 10);
            $parts = $this->fetch($entry->manifest, $work, fn (string $relative, string $target) => $this->obtain($entry, $relative, $target));
            $dumps = $this->openDumps($parts, $work, $identity);
            $progress('loading', 40);
            $result = $this->backups->verifyDumps($dumps['app'], $dumps['engine'], 'restore_test');
            if (isset($parts['files.tar.zst.age'])) {
                $progress('files', 80);
                $result['files'] = $this->countFiles($parts['files.tar.zst.age'], $identity);
            }
            $entry->update(['verified_at' => now(), 'verification' => $result]);

            return $result;
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** @param Closure(string, int): void $progress */
    public function undo(BackupJob $job, Closure $progress): void
    {
        $restore = (array) ($this->state->read()['restore'] ?? []);
        if (($restore['phase'] ?? null) !== self::PHASE_SAFE) {
            throw new BackupFailure('nothing_to_undo', 'Não há restauração aguardando revisão.');
        }
        // depois de uma recuperação de desastre, os bancos anteriores são os vazios da instalação nova
        if (! ($restore['canUndo'] ?? true)) {
            throw new BackupFailure('undo_unavailable', 'Uma recuperação em servidor novo não pode ser desfeita.');
        }
        $names = $this->names((string) $restore['jobId']);
        $this->state->write(['mode' => RuntimeState::MAINTENANCE, 'restore' => [...$restore, 'phase' => self::PHASE_UNDOING]]);
        try {
            $progress('stopping', 20);
            $this->stopServices();
            $progress('swapping', 50);
            $this->swapBack($names);
            $this->tools->dropDatabase($names['app']['discard']);
            $this->tools->dropDatabase($names['engine']['discard']);

            $progress('finishing', 70);
            $this->reinsert($job);
            // o banco antigo voltou com o pedido de restauração ainda em execução
            BackupJob::whereIn('status', [BackupJob::PENDING, BackupJob::RUNNING])->whereKeyNot($job->id)->update(['status' => BackupJob::FAILED, 'error_code' => 'restore_undone', 'error_detail' => 'A restauração foi desfeita pelo administrador.', 'finished_at' => now(), 'sealed_secret' => null]);
            $this->prepareCommands();
            $this->syncCatalog();
            $by = $job->requested_by ? User::find($job->requested_by) : null;
            AuditLogger::record('backup.restore_undone', 'backup_catalog', (string) ($restore['backupId'] ?? ''), ['job' => $job->id], null, $by);

            $progress('starting', 85);
            $this->startServices();
            $this->state->write(['mode' => RuntimeState::NORMAL, 'restore' => null, 'operation' => null]);
        } catch (Throwable $e) {
            // sem a troca, volta ao modo seguro; com ela, termina de religar. O sistema não fica preso em manutenção.
            try {
                $this->finishInterruptedUndo($names, $restore);
            } catch (Throwable $inner) {
                Log::critical('Backup: o desfazer falhou e não foi possível voltar sozinho', ['error' => Masker::error($inner)]);
                $this->state->write(['failed' => 'O desfazer falhou e não foi possível voltar sozinho. Siga o runbook: Backup e recuperação.']);
            }
            throw $e;
        }
    }

    /** Fim do modo seguro: decide os e-mails pendentes e apaga os bancos antigos. Depois disso não há mais como desfazer. */
    public function release(BackupJob $job): void
    {
        $restore = (array) ($this->state->read()['restore'] ?? []);
        if (($restore['phase'] ?? null) !== self::PHASE_SAFE) {
            throw new BackupFailure('nothing_to_release', 'Não há restauração aguardando revisão.');
        }
        $discard = ($job->params['notifications'] ?? 'discard') === 'discard';
        $discarded = $discard ? NotificationMessage::whereIn('state', [NotificationMessage::QUEUED, NotificationMessage::RETRYING])
            ->update(['state' => NotificationMessage::SKIPPED, 'error_code' => 'discarded_after_restore', 'locked_until' => null]) : 0;
        $names = $this->names((string) $restore['jobId']);
        $this->tools->dropDatabase($names['app']['previous']);
        $this->tools->dropDatabase($names['engine']['previous']);
        $this->state->write(['mode' => RuntimeState::NORMAL, 'restore' => null, 'operation' => null]);
        $by = $job->requested_by ? User::find($job->requested_by) : null;
        AuditLogger::record('backup.safe_mode_released', 'backup_catalog', (string) ($restore['backupId'] ?? ''), ['notifications' => $discard ? 'discard' : 'send', 'discarded' => $discarded], null, $by);
    }

    /**
     * Recuperação de desastre: stack nova, com os arquivos já baixados em $dir. Usa o mesmo caminho da restauração da tela,
     * sem parar serviços: só postgres e storage estão de pé.
     *
     * @param  Closure(string, int): void  $progress
     */
    public function import(string $dir, string $identity, Closure $progress): void
    {
        $raw = (string) @file_get_contents($dir.'/'.BackupPaths::MANIFEST);
        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            throw new BackupFailure('manifest_invalid', 'O manifesto do backup não foi encontrado ou é inválido.');
        }
        $this->assertCompatible($manifest);
        $work = $this->storage->stagingDir('import-'.$manifest['id']);
        $names = $this->names((string) $manifest['id']);
        try {
            $progress('fetching', 10);
            $parts = $this->fetch($manifest, $work, function (string $relative, string $target) use ($dir) {
                copy($dir.'/'.basename($relative), $target);
            });
            $dumps = $this->openDumps($parts, $work, $identity);
            $progress('loading', 30);
            $this->load($dumps, $names);
            $progress('swapping', 60);
            $this->tools->renameDatabases($this->swapMap($names));
            $progress('finishing', 70);
            DB::purge('pgsql');
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('bpm:storage-init');
            if (isset($parts['files.tar.zst.age'])) {
                $progress('files', 80);
                $this->restoreFiles($parts['files.tar.zst.age'], $identity);
            }
            $this->prepareAfterSwap();
            $this->markOrigin($manifest);
            $this->syncCatalog();
            AuditLogger::record('backup.restored', 'backup_catalog', (string) $manifest['id'], ['scope' => $manifest['scope'], 'disasterRecovery' => true]);
            $this->state->write(['mode' => RuntimeState::SAFE, 'operation' => null, 'restore' => [
                'jobId' => (string) $manifest['id'], 'backupId' => $manifest['id'], 'phase' => self::PHASE_SAFE, 'restoredTo' => $manifest['createdAt'], 'canUndo' => false,
            ]]);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** Ao subir, o agente retoma pelo diário uma restauração interrompida. */
    public function recover(): void
    {
        $state = $this->state->read();
        if (($state['mode'] ?? null) === RuntimeState::PAUSE) {
            $this->state->write(['mode' => RuntimeState::NORMAL]);
        }
        $restore = (array) ($state['restore'] ?? []);
        if (($state['mode'] ?? null) !== RuntimeState::MAINTENANCE || $restore === []) {
            return;
        }
        $names = $this->names((string) $restore['jobId']);
        $phase = $restore['phase'] ?? null;
        // o agente pode ter caído entre a troca e o registro no diário: o que existe no banco decide
        if ($phase === self::PHASE_STOPPING && ! $this->tools->databaseExists($names['app']['incoming']) && $this->tools->databaseExists($names['app']['previous'])) {
            $phase = self::PHASE_SWAPPED;
        }
        Log::warning('Backup: retomando restauração interrompida', ['phase' => $phase]);
        match ($phase) {
            self::PHASE_SWAPPED => $this->rollback($names),
            self::PHASE_UNDOING => $this->finishInterruptedUndo($names, $restore),
            default => $this->abortBeforeSwap($names),
        };
    }

    /** @param Closure(string, int): void $progress */
    private function swapIn(BackupJob $job, BackupCatalogEntry $entry, array $names, Closure $progress): void
    {
        $journal = ['jobId' => $job->id, 'backupId' => $entry->id, 'phase' => self::PHASE_STOPPING, 'restoredTo' => $entry->backup_at->toIso8601String(), 'startedAt' => now('UTC')->toIso8601String()];
        $this->state->write(['mode' => RuntimeState::MAINTENANCE, 'restore' => $journal]);
        $progress('stopping', 70);
        try {
            $this->stopServices();
            $progress('swapping', 75);
            $this->tools->renameDatabases($this->swapMap($names));
        } catch (Throwable $e) {
            $this->abortBeforeSwap($names);
            throw $e;
        }
        $this->state->write(['restore' => [...$journal, 'phase' => self::PHASE_SWAPPED]]);
        try {
            $progress('finishing', 80);
            DB::purge('pgsql');
            Artisan::call('migrate', ['--force' => true]);
            $this->prepareAfterSwap();
            $this->markOrigin($entry->manifest);
            $this->reinsert($job);
            $tail = $this->archiveAuditTail($names['app']['previous'], $entry, $job);
            $this->syncCatalog();
            $by = $job->requested_by ? User::find($job->requested_by) : null;
            AuditLogger::record('backup.restored', 'backup_catalog', $entry->id, ['scope' => $entry->scope, 'job' => $job->id, 'justification' => $job->params['justification'] ?? null, 'auditTail' => $tail], null, $by);

            $progress('starting', 90);
            $this->startServices();
        } catch (Throwable $e) {
            $this->rollback($names);
            throw $e;
        }
        $this->state->write(['mode' => RuntimeState::SAFE, 'operation' => null, 'restore' => [...$journal, 'phase' => self::PHASE_SAFE, 'auditEvents' => $tail['count']]]);
    }

    /** Volta aos bancos antigos e religa tudo. Se nem isso der certo, fica em manutenção à espera do operador. */
    private function rollback(array $names): void
    {
        try {
            $this->stopServices();
            // uma tentativa anterior pode já ter voltado: sem o banco antigo, não há o que trocar
            if ($this->tools->databaseExists($names['app']['previous'])) {
                $this->swapBack($names);
            }
            $this->tools->dropDatabase($names['app']['discard']);
            $this->tools->dropDatabase($names['engine']['discard']);
            $this->startServices();
            $this->state->write(['mode' => RuntimeState::NORMAL, 'restore' => null, 'operation' => null]);
        } catch (Throwable $e) {
            Log::critical('Backup: a volta aos bancos antigos falhou', ['error' => Masker::error($e)]);
            $this->state->write(['failed' => 'A restauração falhou e não foi possível voltar sozinho. Siga o runbook: Backup e recuperação.']);
            throw $e;
        }
    }

    /** Antes da troca, nada mudou nos dados em uso: basta religar e limpar o que foi carregado ao lado. */
    private function abortBeforeSwap(array $names): void
    {
        try {
            $this->startServices();
            $this->dropIncoming($names);
            $this->state->write(['mode' => RuntimeState::NORMAL, 'restore' => null, 'operation' => null]);
        } catch (Throwable $e) {
            Log::critical('Backup: os serviços não voltaram depois de cancelar a restauração', ['error' => Masker::error($e)]);
            $this->state->write(['failed' => 'Os dados não mudaram, mas os serviços não voltaram sozinhos. Siga o runbook: Backup e recuperação.']);
            throw $e;
        }
    }

    private function finishInterruptedUndo(array $names, array $restore): void
    {
        // desfazer interrompido: a troca é atômica, então basta olhar qual banco ainda existe
        if ($this->tools->databaseExists($names['app']['previous'])) {
            $this->startServices();
            $this->state->write(['mode' => RuntimeState::SAFE, 'restore' => [...$restore, 'phase' => self::PHASE_SAFE], 'operation' => null]);

            return;
        }
        $this->tools->dropDatabase($names['app']['discard']);
        $this->tools->dropDatabase($names['engine']['discard']);
        $this->startServices();
        $this->state->write(['mode' => RuntimeState::NORMAL, 'restore' => null, 'operation' => null]);
    }

    private function swapBack(array $names): void
    {
        $this->tools->renameDatabases([
            $names['app']['current'] => $names['app']['discard'], $names['app']['previous'] => $names['app']['current'],
            $names['engine']['current'] => $names['engine']['discard'], $names['engine']['previous'] => $names['engine']['current'],
        ]);
        DB::purge('pgsql');
    }

    /** @return array<string,string> */
    private function swapMap(array $names): array
    {
        return [
            $names['app']['current'] => $names['app']['previous'], $names['app']['incoming'] => $names['app']['current'],
            $names['engine']['current'] => $names['engine']['previous'], $names['engine']['incoming'] => $names['engine']['current'],
        ];
    }

    /** @return array{app:array<string,string>, engine:array<string,string>} */
    private function names(string $seed): array
    {
        $suffix = strtolower(substr((string) preg_replace('/[^a-zA-Z0-9]/', '', strrev($seed)), 0, 8));
        $build = fn (string $db) => ['current' => $db, 'incoming' => "{$db}_restore", 'previous' => "{$db}_pre_$suffix", 'discard' => "{$db}_discard_$suffix"];

        return ['app' => $build($this->tools->appDatabase()), 'engine' => $build($this->tools->engineDatabase())];
    }

    private function validate(BackupCatalogEntry $entry): void
    {
        $this->assertCompatible($entry->manifest);
        $relative = BackupPaths::part($entry->id, BackupPaths::MANIFEST);
        $raw = $entry->in_local ? (string) @file_get_contents($this->storage->local($relative)) : $this->storage->azureGet($relative);
        if (hash('sha256', $raw) !== $entry->manifest_sha256) {
            throw new BackupFailure('manifest_changed', 'O manifesto deste backup mudou depois de registrado. Por segurança, ele não será restaurado.');
        }
        $free = disk_free_space($this->storage->root());
        if ($free !== false && $free < $entry->bytes * 4 + $this->tools->databaseBytes()) {
            throw new BackupFailure('disk_full', 'Não há espaço em disco para restaurar este backup.');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertCompatible(array $manifest): void
    {
        if (($manifest['organizationKey'] ?? null) !== BackupPaths::organization()) {
            throw new BackupFailure('wrong_organization', 'Este backup é de outra organização.');
        }
        $last = (string) ($manifest['lastMigration'] ?? '');
        if ($last !== '' && ! is_file(database_path("migrations/$last.php"))) {
            throw new BackupFailure('backup_newer_than_app', 'Este backup foi feito por uma versão mais nova do sistema. Atualize a aplicação antes de restaurar.');
        }
    }

    /**
     * Junta as partes no diretório de trabalho e confere o SHA-256 de cada uma.
     *
     * @param  array<string,mixed>  $manifest
     * @param  Closure(string, string): void  $obtain
     * @return array<string,string> nome da parte => caminho
     */
    private function fetch(array $manifest, string $work, Closure $obtain): array
    {
        $paths = [];
        foreach ($manifest['parts'] as $part) {
            if ($part['kind'] === 'config') {
                continue; // configuração só volta pelo roteiro de recuperação
            }
            $target = $work.'/'.$part['name'];
            $obtain(BackupPaths::part($manifest['id'], $part['name']), $target);
            if (! is_file($target) || hash_file('sha256', $target) !== $part['sha256']) {
                throw new BackupFailure('part_corrupted', "A parte {$part['name']} não confere com o manifesto.");
            }
            $paths[$part['name']] = $target;
        }

        return $paths;
    }

    private function obtain(BackupCatalogEntry $entry, string $relative, string $target): void
    {
        if ($entry->in_local && is_file($this->storage->local($relative))) {
            copy($this->storage->local($relative), $target);

            return;
        }
        if (! $entry->in_azure) {
            throw new BackupFailure('backup_unavailable', 'Os arquivos deste backup não estão mais disponíveis.');
        }
        $this->storage->download($relative, $target);
    }

    /**
     * @param  array<string,string>  $parts
     * @return array{app:string, engine:string}
     */
    private function openDumps(array $parts, string $work, string $identity): array
    {
        foreach (['bpm_app.dump.zst.age', 'flowable.dump.zst.age'] as $name) {
            if (! isset($parts[$name])) {
                throw new BackupFailure('part_missing', "O backup não tem a parte $name.");
            }
        }
        $this->tools->decrypt($parts['bpm_app.dump.zst.age'], "$work/bpm_app.dump", $identity);
        $this->tools->decrypt($parts['flowable.dump.zst.age'], "$work/flowable.dump", $identity);

        return ['app' => "$work/bpm_app.dump", 'engine' => "$work/flowable.dump"];
    }

    /** @param array{app:string, engine:string} $dumps */
    private function load(array $dumps, array $names): void
    {
        $this->dropIncoming($names);
        $this->tools->createDatabase($names['app']['incoming'], $this->tools->appOwner());
        $this->tools->createDatabase($names['engine']['incoming'], $this->tools->engineOwner());
        $this->tools->restoreDump($dumps['app'], $names['app']['incoming']);
        $this->tools->restoreDump($dumps['engine'], $names['engine']['incoming']);
    }

    private function dropIncoming(array $names): void
    {
        $this->tools->dropDatabase($names['app']['incoming']);
        $this->tools->dropDatabase($names['engine']['incoming']);
    }

    private function restoreFiles(string $part, string $identity): void
    {
        $reader = $this->tools->decryptingReader($part, $identity);
        try {
            $this->files->restore($reader);
        } finally {
            $this->tools->closePipe($reader, 'leitura do pacote de arquivos');
        }
    }

    private function countFiles(string $part, string $identity): int
    {
        $reader = $this->tools->decryptingReader($part, $identity);
        $count = 0;
        try {
            foreach (TarStream::read($reader) as $entry) {
                $count++;
            }
        } finally {
            $this->tools->closePipe($reader, 'leitura do pacote de arquivos');
        }

        return $count;
    }

    private function stopServices(): void
    {
        foreach ((array) config('bpm.backup.stop_services') as $service) {
            $this->docker->stop((string) $service);
        }
    }

    private function startServices(): void
    {
        $this->docker->start('flowable-rest');
        for ($attempt = 0; ! $this->engine->health(); $attempt++) {
            if ($attempt >= self::ENGINE_CHECKS) {
                throw new BackupFailure('engine_not_ready', 'O Flowable não voltou depois da troca dos bancos.');
            }
            Sleep::sleep(3);
        }
        foreach (array_diff((array) config('bpm.backup.stop_services'), ['flowable-rest']) as $service) {
            $this->docker->start((string) $service);
        }
    }

    /** O banco restaurado volta com o que estava em andamento na hora do backup; nada disso pode seguir sem reconciliar. */
    private function prepareAfterSwap(): void
    {
        $this->prepareCommands();
        DB::table('jobs')->delete();
        Cache::flush();
        AuthSession::whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => 'restore']);
        BackupJob::whereIn('status', [BackupJob::PENDING, BackupJob::RUNNING])->update(['status' => BackupJob::FAILED, 'error_code' => 'superseded_by_restore', 'finished_at' => now(), 'sealed_secret' => null]);
    }

    /**
     * O pedido que gerou o backup estava em andamento quando o banco foi copiado: no histórico restaurado ele aparece concluído.
     *
     * @param  array<string,mixed>  $manifest
     */
    private function markOrigin(array $manifest): void
    {
        if (! empty($manifest['jobId'])) {
            BackupJob::whereKey($manifest['jobId'])->update(['status' => BackupJob::SUCCEEDED, 'backup_id' => $manifest['id'], 'stage' => null, 'percent' => 100, 'error_code' => null, 'error_detail' => null]);
        }
    }

    /** attempts = 1: o próximo claim sobe para 2 e força reconciliar antes de reenviar. Sem isso, START duplicaria o processo. */
    private function prepareCommands(): void
    {
        EngineCommand::whereIn('state', [EngineCommand::PENDING, EngineCommand::SENDING, EngineCommand::RECONCILIATION_REQUIRED])->update([
            'state' => EngineCommand::RECONCILIATION_REQUIRED, 'attempts' => DB::raw('case when attempts < 1 then 1 else attempts end'), 'locked_until' => null, 'available_at' => now(),
        ]);
    }

    /** O pedido em execução ficou no banco que saiu de uso: regrava para o histórico continuar a mostrá-lo. */
    private function reinsert(BackupJob $job): void
    {
        $row = $job->getAttributes();
        $row['requested_by'] = $job->requested_by && User::whereKey($job->requested_by)->exists() ? $job->requested_by : null;
        DB::table('backup_job')->where('id', $job->id)->delete();
        DB::table('backup_job')->insert($row);
    }

    /**
     * Guarda os eventos de auditoria posteriores ao backup: eles saíram do banco em uso, mas a trilha é append-only.
     *
     * @return array{count:int, key:?string, sha256:?string}
     */
    private function archiveAuditTail(string $database, BackupCatalogEntry $entry, BackupJob $job): array
    {
        $events = $this->tools->auditEventsSince($database, $entry->backup_at->toDateTimeString());
        if ($events === []) {
            return ['count' => 0, 'key' => null, 'sha256' => null];
        }
        $jsonl = implode("\n", array_map(fn ($e) => (string) json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $events))."\n";
        $gz = (string) gzencode($jsonl, 9);
        $key = 'audit-archive/restores/'.$job->id.'.jsonl.gz';
        $sha = hash('sha256', $gz);
        $disk = Storage::disk((string) config('bpm.audit.archive_disk'));
        $disk->put($key, $gz);
        $disk->put($key.'.manifest.json', (string) json_encode(['count' => count($events), 'sha256' => $sha, 'restoredTo' => $entry->backup_at->toIso8601String(), 'job' => $job->id], JSON_PRETTY_PRINT));

        return ['count' => count($events), 'key' => $key, 'sha256' => $sha];
    }

    /** O catálogo volta com o banco; refazê-lo não pode derrubar uma restauração que já deu certo. */
    private function syncCatalog(): void
    {
        try {
            $this->catalog->sync();
        } catch (Throwable $e) {
            Log::warning('Backup: catálogo não foi refeito após a restauração', ['error' => Masker::error($e)]);
        }
    }
}
