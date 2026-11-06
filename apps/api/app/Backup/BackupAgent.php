<?php

namespace App\Backup;

use App\Audit\AuditContext;
use App\Models\BackupCatalogEntry;
use App\Models\BackupJob;
use App\Models\User;
use App\Notifications\Notifier;
use App\Settings\Types\BackupSettings;
use App\Storage\Azure\AzureBlobException;
use App\Support\AuditLogger;
use App\Support\Masker;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Serviço backup-agent: executa um pedido de backup_job por vez, fora das filas da aplicação. Lê só campos validados de uma
 * tabela de tipos fixos: a fila do Laravel desserializa PHP, e quem escrevesse no banco executaria código aqui, no serviço
 * com mais acesso da stack.
 */
class BackupAgent
{
    private bool $stopping = false;

    private string $sealKeys = '';

    private ?InvokedProcess $beat = null;

    public function __construct(
        private readonly RuntimeState $state,
        private readonly BackupRunner $backups,
        private readonly RestoreRunner $restores,
        private readonly BackupCatalog $catalog,
        private readonly BackupStorage $storage,
        private readonly Notifier $notifier,
    ) {}

    public function run(): void
    {
        pcntl_async_signals(true);
        // SIGQUIT: a imagem base do php-fpm usa esse sinal no docker stop; o Dockerfile troca, mas o agente aceita os dois
        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, fn () => $this->stopping = true);
        }
        $this->boot();
        while (! $this->stopping) {
            try {
                $job = $this->next();
                $job ? $this->handle($job) : sleep(3);
            } catch (Throwable $e) {
                // banco indisponível, por exemplo: continua tentando
                Log::error('Backup: falha no agente', ['error' => Masker::error($e)]);
                sleep(5);
            }
        }
        $this->beat?->signal(SIGTERM);
    }

    /** Executa um pedido já reservado. */
    public function handle(BackupJob $job): void
    {
        AuditContext::as('agente:backup', function () use ($job) {
            try {
                $error = $this->execute($job);
                $job->update(['status' => BackupJob::SUCCEEDED, 'percent' => 100, 'stage' => null, 'finished_at' => now(), 'sealed_secret' => null, 'error_code' => $error ? 'azure_upload_failed' : null, 'error_detail' => $error]);
                if ($error) {
                    $this->alert($job, 'Backup concluído sem a cópia no Azure', "O backup ficou no servidor, mas o envio ao Azure falhou: $error");
                }
            } catch (Throwable $e) {
                $this->fail($job, $e);
            } finally {
                $this->state->write(['operation' => null]);
            }
        });
    }

    public function boot(): void
    {
        $this->storage->ensureDir($this->storage->root());
        $this->sealKeys = sodium_crypto_box_keypair();
        $this->state->write(['sealKey' => base64_encode(sodium_crypto_box_publickey($this->sealKeys))]);
        $this->startHeartbeat();
        try {
            $this->restores->recover();
            BackupJob::where('status', BackupJob::RUNNING)->update(['status' => BackupJob::FAILED, 'error_code' => 'agent_restarted', 'error_detail' => 'O agente reiniciou durante a execução.', 'finished_at' => now(), 'sealed_secret' => null]);
        } catch (Throwable $e) {
            Log::error('Backup: falha ao retomar depois de reiniciar', ['error' => Masker::error($e)]);
        }
    }

    public function sealPublicKey(): string
    {
        return sodium_crypto_box_publickey($this->sealKeys);
    }

    /** @return ?string mensagem quando o trabalho deu certo com ressalva */
    private function execute(BackupJob $job): ?string
    {
        $progress = fn (string $stage, int $percent) => $this->progress($job, $stage, $percent);
        if ($job->kind === BackupJob::BACKUP) {
            return $this->backup($job, $progress);
        }
        match ($job->kind) {
            BackupJob::RESTORE => $this->withKey($job, function (string $identity) use ($job, $progress) {
                $this->restores->restore($job, $this->entry($job), $identity, $progress);
            }),
            BackupJob::RESTORE_TEST => $this->withKey($job, function (string $identity) use ($job, $progress) {
                $entry = $this->entry($job);
                $result = $this->restores->test($entry, $identity, $progress);
                AuditLogger::record('backup.restore_tested', 'backup_catalog', $entry->id, ['tables' => $result['app']['tables'] ?? null, 'files' => $result['files'] ?? null], null, $this->requester($job));
            }),
            BackupJob::DELETE => $this->delete($job),
            BackupJob::COPY_AZURE => $this->copyToAzure($job),
            BackupJob::CATALOG_SYNC => $this->catalog->sync(),
            BackupJob::UNDO_RESTORE => $this->restores->undo($job, $progress),
            BackupJob::RELEASE => $this->restores->release($job),
            default => throw new BackupFailure('unknown_kind', 'Tipo de pedido desconhecido.'),
        };

        return null;
    }

    private function backup(BackupJob $job, \Closure $progress): ?string
    {
        $p = (array) $job->params;
        $result = $this->backups->run([
            'scope' => (string) ($p['scope'] ?? BackupSettings::DATABASE), 'azure' => (bool) ($p['azure'] ?? false), 'verify' => (bool) ($p['verify'] ?? false),
            'origin' => $job->schedule_id ? BackupCatalogEntry::SCHEDULE : BackupCatalogEntry::MANUAL, 'scheduleId' => $job->schedule_id, 'jobId' => $job->id,
        ], $progress);
        $job->update(['backup_id' => $result['entry']->id]);
        AuditLogger::record('backup.completed', 'backup_catalog', $result['entry']->id, ['scope' => $result['entry']->scope, 'bytes' => $result['entry']->bytes, 'azure' => $result['entry']->in_azure], null, $this->requester($job));

        return $result['azureError'];
    }

    private function delete(BackupJob $job): void
    {
        $entry = $this->entry($job);
        $this->catalog->delete($entry);
        $this->catalog->cleanImages();
        AuditLogger::record('backup.deleted', 'backup_catalog', $entry->id, ['scope' => $entry->scope], null, $this->requester($job));
    }

    private function copyToAzure(BackupJob $job): void
    {
        $entry = $this->entry($job);
        if (! $this->storage->azureEnabled()) {
            throw new BackupFailure('azure_unavailable', 'O envio ao Azure não está ativo.');
        }
        $this->backups->uploadToAzure($entry->manifest);
        $entry->update(['in_azure' => true]);
    }

    /**
     * A chave de recuperação chega cifrada para este processo, vira um arquivo em memória durante o trabalho e some.
     *
     * @param  callable(string): void  $work
     */
    private function withKey(BackupJob $job, callable $work): void
    {
        $sealed = base64_decode((string) $job->sealed_secret, true);
        $key = $sealed === false ? false : sodium_crypto_box_seal_open($sealed, $this->sealKeys);
        $job->update(['sealed_secret' => null]);
        if ($key === false) {
            throw new BackupFailure('agent_restarted', 'O agente reiniciou depois do pedido. Informe a chave de recuperação de novo.');
        }
        $file = rtrim((string) config('bpm.backup.key_dir'), '/').'/'.$job->id.'.key';
        touch($file);
        chmod($file, 0600);
        file_put_contents($file, $key."\n");
        try {
            $work($file);
        } finally {
            @unlink($file);
            sodium_memzero($key);
        }
    }

    private function entry(BackupJob $job): BackupCatalogEntry
    {
        return BackupCatalogEntry::find($job->backup_id) ?? throw new BackupFailure('backup_unavailable', 'Este backup não está mais no catálogo.');
    }

    private function requester(BackupJob $job): ?User
    {
        return $job->requested_by ? User::find($job->requested_by) : null;
    }

    private function progress(BackupJob $job, string $stage, int $percent): void
    {
        $this->state->write(['operation' => ['jobId' => $job->id, 'kind' => $job->kind, 'stage' => $stage, 'percent' => $percent]]);
        try {
            $job->update(['stage' => $stage, 'percent' => $percent]);
        } catch (Throwable) {
            // durante a troca o banco some por instantes: quem acompanha lê o arquivo de estado
        }
    }

    private function fail(BackupJob $job, Throwable $e): void
    {
        $code = match (true) {
            $e instanceof BackupFailure => $e->errorCode,
            $e instanceof AzureBlobException => $e->errorCode,
            default => 'internal_error',
        };
        $detail = $e instanceof BackupFailure || $e instanceof AzureBlobException ? $e->getMessage() : 'Falha inesperada. Consulte os logs do serviço backup-agent.';
        Log::error('Backup: pedido falhou', ['job' => $job->id, 'kind' => $job->kind, 'code' => $code, 'error' => Masker::error($e)]);
        $job->update(['status' => BackupJob::FAILED, 'error_code' => $code, 'error_detail' => mb_substr($detail, 0, 1000), 'finished_at' => now(), 'sealed_secret' => null]);
        AuditLogger::record($job->kind === BackupJob::RESTORE ? 'backup.restore_failed' : 'backup.failed', 'backup_job', $job->id, ['kind' => $job->kind, 'code' => $code], null, $this->requester($job), outcome: 'failure');
        $this->alert($job, 'Falha no backup', "A operação \"{$job->kind}\" falhou: $detail");
    }

    private function alert(BackupJob $job, string $subject, string $body): void
    {
        try {
            $this->notifier->adminAlert("backup:{$job->id}", $subject.' — '.config('bpm.organization.name'), $body);
        } catch (Throwable $e) {
            Log::warning('Backup: alerta não enviado', ['error' => Masker::error($e)]);
        }
    }

    private function next(): ?BackupJob
    {
        $job = BackupJob::where('status', BackupJob::PENDING)->orderBy('created_at')->first();
        if ($job === null) {
            return null;
        }
        $claimed = BackupJob::whereKey($job->id)->where('status', BackupJob::PENDING)->update(['status' => BackupJob::RUNNING, 'started_at' => now()]);

        return $claimed === 1 ? $job->fresh() : null;
    }

    /** Sinal de vida em processo separado: um dump longo bloqueia este, e a tela não pode achar que o agente caiu. */
    private function startHeartbeat(): void
    {
        $file = escapeshellarg($this->state->beatFile());
        $this->beat = Process::forever()->start(['sh', '-c', 'while kill -0 '.getmypid()." 2>/dev/null; do touch $file; sleep 10; done"]);
    }
}
