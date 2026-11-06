<?php

namespace App\Backup;

use App\Models\BackupCatalogEntry;
use App\Models\BackupJob;
use App\Notifications\Notifier;
use App\Settings\SettingsRepository;
use App\Settings\Types\BackupSettings;
use Illuminate\Support\Carbon;

/**
 * Cria os pedidos dos agendamentos (scheduler) e avisa quando um deles atrasa. Horário perdido por queda roda no próximo
 * minuto, só o mais recente: o índice único de backup_job garante uma execução por horário.
 */
class BackupScheduler
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly RuntimeState $state,
        private readonly Notifier $notifier,
    ) {}

    public function tick(): int
    {
        $cfg = $this->settings->value(app(BackupSettings::class));
        if ($this->state->holdsSchedule() || empty($cfg['recoveryKey'])) {
            return 0;
        }
        $created = 0;
        foreach ((array) $cfg['schedules'] as $schedule) {
            if (! ($schedule['enabled'] ?? false)) {
                continue;
            }
            $due = BackupSchedule::lastDue($schedule, (string) $cfg['timezone'], now());
            if ($due === null) {
                continue;
            }
            $job = BackupJob::firstOrCreate(['schedule_id' => $schedule['id'], 'scheduled_for' => $due->toDateTimeString()], [
                'kind' => BackupJob::BACKUP, 'params' => ['scope' => $schedule['scope'], 'azure' => $schedule['azure'], 'verify' => $schedule['verify']],
            ]);
            $created += $job->wasRecentlyCreated ? 1 : 0;
            $this->alertIfOverdue($schedule);
        }
        $this->alertIfAgentDown();

        return $created;
    }

    /** @return ?Carbon próxima execução entre os agendamentos ativos */
    public function nextRun(): ?Carbon
    {
        $cfg = $this->settings->value(app(BackupSettings::class));
        $next = collect((array) $cfg['schedules'])->where('enabled', true)->map(fn ($s) => BackupSchedule::next($s, (string) $cfg['timezone'], now()))->sort()->first();

        return $next ? Carbon::instance($next) : null;
    }

    /** @param array<string,mixed> $schedule */
    private function alertIfOverdue(array $schedule): void
    {
        $last = BackupCatalogEntry::where('schedule_id', $schedule['id'])->max('backup_at');
        $since = Carbon::parse($last ?? $schedule['createdAt']);
        if ($since->diffInHours(now(), true) < BackupSchedule::overdueAfterHours($schedule)) {
            return;
        }
        $this->notifier->adminAlert('backup-overdue:'.$schedule['id'].':'.now()->format('Y-m-d'), 'Backup atrasado — '.config('bpm.organization.name'),
            'Um agendamento de backup está sem execução concluída há mais tempo do que o previsto. Confira Configurações → Backup e recuperação.');
    }

    private function alertIfAgentDown(): void
    {
        if ($this->state->agentOnline() || ! BackupJob::where('status', BackupJob::PENDING)->where('created_at', '<', now()->subMinutes(15))->exists()) {
            return;
        }
        $this->notifier->adminAlert('backup-agent-down:'.now()->format('Y-m-d'), 'Agente de backup fora do ar — '.config('bpm.organization.name'),
            'Há pedidos de backup parados e o serviço backup-agent não responde. Confira o serviço no servidor.');
    }
}
