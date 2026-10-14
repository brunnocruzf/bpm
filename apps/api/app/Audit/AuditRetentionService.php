<?php

namespace App\Audit;

use App\Models\AuditEvent;
use App\Models\DirectorySyncRun;
use App\Models\NotificationMessage;
use App\Models\SettingValidation;
use App\Settings\SettingsRepository;
use App\Settings\Types\AuditRetentionSettings;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Retenção aprovada: arquiva (JSONL gzip + manifesto SHA-256 no storage) e só então expurga,
 * em lotes, abrindo o guarda do trigger append-only apenas dentro da própria transação.
 * Sem prazo aprovado nada é removido. O expurgo gera o próprio evento de auditoria.
 */
class AuditRetentionService
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array{events:int, archives:list<string>, notifications:int, syncRuns:int, validations:int} */
    public function run(): array
    {
        $cfg = $this->settings->value(app(AuditRetentionSettings::class));
        $result = ['events' => 0, 'archives' => [], 'notifications' => 0, 'syncRuns' => 0, 'validations' => 0];

        if ($cfg['retentionDays'] !== null) {
            $cutoff = now()->subDays((int) $cfg['retentionDays']);
            $disk = Storage::disk((string) config('bpm.audit.archive_disk'));
            while (true) {
                $batch = AuditEvent::where('occurred_at', '<', $cutoff)->orderBy('occurred_at')->orderBy('id')->limit(1000)->get();
                if ($batch->isEmpty()) {
                    break;
                }
                $jsonl = $batch->map(fn (AuditEvent $e) => json_encode($e->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n")."\n";
                $gz = (string) gzencode($jsonl, 9);
                $key = 'audit-archive/'.now()->format('Y/m').'/audit-'.$batch->first()->occurred_at->format('Ymd').'-'.Str::uuid().'.jsonl.gz';
                $disk->put($key, $gz);
                $manifest = ['count' => $batch->count(), 'sha256' => hash('sha256', $gz), 'from' => $batch->first()->occurred_at->toIso8601String(), 'to' => $batch->last()->occurred_at->toIso8601String(), 'cutoff' => $cutoff->toIso8601String(), 'approval' => $cfg['approvalReference'], 'createdAt' => now()->toIso8601String()];
                $disk->put($key.'.manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                if ($disk->get($key) === null || hash('sha256', (string) $disk->get($key)) !== $manifest['sha256']) {
                    break; // arquivo não confirmado: não expurga
                }
                DB::transaction(function () use ($batch) {
                    $token = (string) Str::uuid();
                    DB::table('audit_purge_guard')->insert(['token' => $token]);
                    DB::table('audit_event')->whereIn('id', $batch->pluck('id'))->delete();
                    DB::table('audit_purge_guard')->where('token', $token)->delete();
                });
                $result['events'] += $batch->count();
                $result['archives'][] = $key;
            }
        }
        if ($cfg['retentionDays'] !== null) {
            // histórico de testes das configurações segue o prazo aprovado da auditoria
            $result['validations'] = SettingValidation::where('created_at', '<', now()->subDays((int) $cfg['retentionDays']))->delete();
        }
        if ($cfg['notificationRetentionDays'] !== null) {
            $result['notifications'] = NotificationMessage::whereIn('state', [NotificationMessage::SENT, NotificationMessage::SKIPPED, NotificationMessage::FAILED])
                ->where('created_at', '<', now()->subDays((int) $cfg['notificationRetentionDays']))->delete();
        }
        if ($cfg['syncRunRetentionDays'] !== null) {
            $result['syncRuns'] = DirectorySyncRun::whereNotIn('status', [DirectorySyncRun::QUEUED, DirectorySyncRun::RUNNING])
                ->where('created_at', '<', now()->subDays((int) $cfg['syncRunRetentionDays']))->delete();
        }
        if ($result['events'] || $result['notifications'] || $result['syncRuns'] || $result['validations']) {
            AuditContext::as('agendador:retenção', fn () => AuditLogger::record('audit.retention_purged', 'audit_event', null, [
                'events' => $result['events'], 'archives' => $result['archives'], 'notifications' => $result['notifications'], 'syncRuns' => $result['syncRuns'], 'validations' => $result['validations'],
                'retentionDays' => $cfg['retentionDays'], 'approval' => $cfg['approvalReference'],
            ]));
        }

        return $result;
    }
}
