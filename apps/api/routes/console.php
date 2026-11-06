<?php

use App\Audit\AuditRetentionService;
use App\Backup\BackupAgent;
use App\Backup\BackupFailure;
use App\Backup\BackupScheduler;
use App\Backup\RestoreRunner;
use App\Backup\RuntimeState;
use App\Engine\EngineCommandProcessor;
use App\Engine\ExternalJobWorker;
use App\Engine\ProjectionSync;
use App\Identity\DirectorySyncService;
use App\Identity\UserPhotoService;
use App\Integrations\Rest\OpenApiImporter;
use App\Models\Attachment;
use App\Models\IntegrationConnection;
use App\Models\ProcessInstance;
use App\Notifications\NotificationSender;
use App\Notifications\Notifier;
use App\Reports\ReportService;
use App\Settings\SecretStore;
use App\Settings\SettingsRepository;
use App\Settings\Types\AttachmentStorageSettings;
use App\Storage\AttachmentStorage;
use App\Storage\Azure\AzureBlobClient;
use App\Support\Masker;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

/*
| Jobs periódicos (container "scheduler" executa `php artisan schedule:work`).
| Em múltiplas réplicas, onOneServer() garante execução singleton via lock de cache.
*/

Artisan::command('bpm:outbox', function (EngineCommandProcessor $processor) {
    $n = $processor->dispatchDue();
    if ($n) {
        $this->info("Comandos processados: $n");
    }
})->purpose('Processa comandos pendentes/reconciliáveis do outbox do engine');

Artisan::command('bpm:reconcile {--all}', function (ProjectionSync $sync, RuntimeState $state) {
    if ($state->holdsEngine()) {
        return;
    }
    // Instâncias em execução sem sincronização recente (timers, integrações, divergências).
    $query = ProcessInstance::where('status', ProcessInstance::RUNNING)->whereNotNull('engine_process_instance_id');
    if (! $this->option('all')) {
        $query->where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<', now()->subSeconds(60)));
    }
    $count = 0;
    foreach ($query->orderBy('synced_at')->limit(200)->get() as $instance) {
        try {
            $sync->syncInstance($instance);
            $count++;
        } catch (Throwable $e) {
            $this->warn("Falha ao reconciliar {$instance->number}: ".Masker::error($e));
        }
    }
    if ($count) {
        $this->info("Instâncias reconciliadas: $count");
    }
})->purpose('Reconcilia projeções de tarefas/instâncias com o engine');

Artisan::command('bpm:external-jobs', function (ExternalJobWorker $worker) {
    try {
        $n = $worker->run();
        if ($n) {
            $this->info("Jobs de integração executados: $n");
        }
    } catch (Throwable $e) {
        $this->warn('Worker de integração: '.Masker::error($e));
    }
})->purpose('Executa service tasks (external worker) do Flowable');

Artisan::command('bpm:notifications', function (NotificationSender $sender) {
    // varredura de segurança: o envio normal é feito pelos jobs da fila "notifications"
    $n = $sender->dispatchDue();
    if ($n) {
        $this->info("Notificações processadas: $n");
    }
})->purpose('Processa notificações vencidas do outbox (retry/backoff)');

Artisan::command('bpm:directory-sync {--now : Solicita execução imediata (delta)} {--full}', function (DirectorySyncService $sync) {
    if ($this->option('now') || $this->option('full')) {
        $run = $sync->request($this->option('full') ? 'full' : 'delta', 'manual', null);
        $this->info("Execução {$run->id} ({$run->status}).");

        return;
    }
    if ($run = $sync->runIfDue()) {
        $this->info("Sincronização agendada solicitada: {$run->id}");
    }
    foreach ($sync->overview()['alerts'] as $alert) {
        if (Cache::add('bpm:directory-alert:'.$alert['code'], 1, 86400)) {
            app(Notifier::class)->adminAlert('directory:'.$alert['code'].':'.now()->format('Ymd'), '[BPM TMSA] Diretório: '.$alert['code'], $alert['message']."\n\nConfigurações → Entra ID — usuários e grupos.");
            $this->warn($alert['message']);
        }
    }
})->purpose('Dispara a sincronização do diretório quando a periodicidade vence e alerta inconsistências');

Artisan::command('bpm:directory-photos', function (UserPhotoService $photos) {
    $purged = $photos->purgeUnneeded();
    $t = $photos->refreshBatch();
    $this->info("Fotos: {$t['checked']} verificada(s), {$t['updated']} atualizada(s), {$t['unchanged']} sem mudança, {$t['skipped']} ignorada(s), {$t['errors']} erro(s); {$purged} removida(s) por política.");
})->purpose('Atualiza as fotos do Entra ID (por etag) dos usuários ativos e apaga as de quem não precisa mais');

Artisan::command('bpm:reports-expire', function (ReportService $reports) {
    $n = $reports->expire();
    if ($n) {
        $this->info("Arquivos de relatório expirados: $n");
    }
})->purpose('Remove artefatos de relatório vencidos (retenção configurável)');

Artisan::command('bpm:audit-retention', function (AuditRetentionService $retention) {
    $r = $retention->run();
    $this->info("Auditoria expurgada (após arquivo): {$r['events']} · notificações: {$r['notifications']} · execuções de sincronização: {$r['syncRuns']}");
})->purpose('Aplica a retenção aprovada: arquiva e expurga eventos antigos');

Artisan::command('bpm:openapi-check', function (OpenApiImporter $importer) {
    // compara o OpenAPI publicado com o importado e MARCA endpoints com contrato alterado/removido (nada é aplicado)
    foreach (IntegrationConnection::whereNotNull('openapi_url')->where('enabled', true)->get() as $c) {
        try {
            $parsed = $importer->fetch((string) $c->openapi_url);
            if ($parsed['hash'] === $c->openapi_hash) {
                $c->forceFill(['openapi_checked_at' => now()])->save();
                $this->info("{$c->key}: contrato sem mudanças.");

                continue;
            }
            $r = $importer->markDrift($parsed['doc'], $c);
            $this->warn("{$c->key}: documento mudou — {$r['changed']} endpoint(s) com contrato alterado, {$r['removed']} removido(s). Reimporte em Integrações → Importar do Swagger.");
        } catch (Throwable $e) {
            $this->warn("{$c->key}: verificação falhou — ".Masker::error($e));
        }
    }
})->purpose('Verifica mudanças no OpenAPI/Swagger das conexões e marca endpoints afetados');

Artisan::command('bpm:secrets-rotate', function (SecretStore $secrets) {
    $this->info('Segredos recriptografados com a chave atual: '.$secrets->rotate());
})->purpose('Recriptografa o cofre após rotação de BPM_SECRETS_KEY (mantenha a anterior em BPM_SECRETS_PREVIOUS_KEYS)');

Artisan::command('bpm:cleanup-orphans', function () {
    $ttl = (int) config('bpm.attachments.orphan_ttl_hours', 24);
    $orphans = Attachment::whereNull('process_instance_id')->whereNull('task_projection_id')->where('created_at', '<', now()->subHours($ttl))->get();
    $removed = 0;
    foreach ($orphans as $a) {
        try {
            app(AttachmentStorage::class)->disk($a->disk)->delete($a->object_key);
            $a->delete();
            $removed++;
        } catch (Throwable $e) {
            $this->warn("Anexo {$a->id}: não removido do armazenamento — ".Masker::error($e));
        }
    }
    $this->info("Anexos órfãos removidos: $removed de ".$orphans->count());
})->purpose('Remove anexos enviados e nunca vinculados a uma solicitação');

Artisan::command('bpm:storage-azure-init', function (SettingsRepository $settings, SecretStore $secrets) {
    if (app()->environment('production')) {
        $this->error('Comando recusado em produção: o contêiner é criado no portal do Azure.');

        return 1;
    }
    $cfg = $settings->value(app(AttachmentStorageSettings::class));
    $a = (array) $cfg['azure'];
    if (($a['endpointMode'] ?? '') !== 'emulator' || ! AttachmentStorageSettings::emulatorAvailable()) {
        $this->error('Salve o destino com o endpoint "emulador" em Configurações → Armazenamento de anexos (BPM_AZURITE_BLOB_ENDPOINT).');

        return 1;
    }
    $key = $secrets->get($a['credentialRef'] ?? null);
    if ($key === null || ($a['container'] ?? '') === '') {
        $this->error('Informe contêiner e chave do emulador na configuração antes de criar o contêiner.');

        return 1;
    }
    (new AzureBlobClient(AttachmentStorageSettings::account($cfg), $key, AttachmentStorageSettings::endpoint($cfg), (string) $a['container'], (string) config('bpm.storage.azure.api_version')))->createContainerIfMissing();
    $this->info("Contêiner {$a['container']} pronto no emulador.");

    return 0;
})->purpose('Cria o contêiner privado no emulador Azurite (somente desenvolvimento/teste)');

Artisan::command('bpm:storage-init', function () {
    $disk = (string) config('bpm.attachments.disk');
    if (config("filesystems.disks.$disk.driver") !== 's3') {
        $this->info("Disco $disk não é S3; nada a fazer.");

        return;
    }
    $adapter = Storage::disk($disk);
    if (! $adapter instanceof AwsS3V3Adapter) {
        $this->error("Disco $disk não é um adaptador S3.");

        return;
    }
    $client = $adapter->getClient();
    $bucket = config("filesystems.disks.$disk.bucket");
    if (! $client->doesBucketExist($bucket)) {
        $client->createBucket(['Bucket' => $bucket]);
        $this->info("Bucket $bucket criado (privado).");
    } else {
        $this->info("Bucket $bucket já existe.");
    }
})->purpose('Cria o bucket privado de anexos no storage S3-compatível');

Artisan::command('bpm:backup-agent', function (BackupAgent $agent) {
    $agent->run();
})->purpose('Serviço backup-agent: executa os pedidos de backup e restauração (ADR-0021)');

Artisan::command('bpm:backup-tick', function (BackupScheduler $scheduler) {
    $n = $scheduler->tick();
    if ($n) {
        $this->info("Backups agendados: $n");
    }
})->purpose('Cria os pedidos dos agendamentos de backup vencidos');

Artisan::command('bpm:backup-import {--dir= : Pasta com o manifest.json e as partes do backup} {--key-file= : Arquivo com a chave de recuperação}', function (RestoreRunner $restores) {
    try {
        $restores->import((string) $this->option('dir'), (string) $this->option('key-file'), fn (string $stage, int $percent) => $this->line("$percent% $stage"));
        $this->info('Backup importado. A aplicação sobe em modo seguro.');
    } catch (BackupFailure $e) {
        $this->error($e->getMessage());

        return 1;
    }
})->purpose('Recuperação de desastre: importa um backup em uma stack nova');

Artisan::command('bpm:backup-recover', function (RestoreRunner $restores) {
    $restores->recover();
    $this->info('Estado atual: '.app(RuntimeState::class)->mode());
})->purpose('Retoma pelo diário uma restauração interrompida');

Schedule::command('bpm:outbox')->everyTenSeconds()->withoutOverlapping()->onOneServer();
Schedule::command('bpm:external-jobs')->everyFifteenSeconds()->withoutOverlapping()->onOneServer();
Schedule::command('bpm:reconcile')->everyThirtySeconds()->withoutOverlapping()->onOneServer();
Schedule::command('bpm:notifications')->everyThirtySeconds()->withoutOverlapping()->onOneServer();
Schedule::command('bpm:cleanup-orphans')->hourly()->onOneServer();
Schedule::command('bpm:directory-photos')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('bpm:directory-sync')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('bpm:reports-expire')->everyFifteenMinutes()->onOneServer();
Schedule::command('bpm:audit-retention')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::command('bpm:openapi-check')->dailyAt('06:10')->withoutOverlapping()->onOneServer();
Schedule::command('bpm:backup-tick')->everyMinute()->withoutOverlapping()->onOneServer();
