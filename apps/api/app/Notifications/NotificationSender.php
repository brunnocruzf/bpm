<?php

namespace App\Notifications;

use App\Audit\AuditContext;
use App\Jobs\SendNotification;
use App\Mail\MailException;
use App\Mail\MailProviderFactory;
use App\Mail\OutgoingMail;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Settings\SettingsRepository;
use App\Settings\Types\BrandingSettings;
use App\Settings\Types\EmailSettings;
use App\Support\AuditLogger;
use App\Support\Correlation;
use App\Support\Masker;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Worker do outbox de e-mail: claim atômico, envio pelo provedor configurado, retry com backoff
 * exponencial para falhas transitórias e dead-letter (failed) para permanentes ou esgotadas.
 * Falha de e-mail nunca afeta a tarefa/instância (que já está válida e visível na inbox).
 */
class NotificationSender
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly MailProviderFactory $factory,
        private readonly EmailLayoutRenderer $emailLayout,
    ) {}

    public function send(string $id): void
    {
        $message = $this->claim($id);
        if (! $message) {
            return;
        }
        Correlation::set($message->correlation_id);
        AuditContext::as('worker:notificações', fn () => $this->deliver($message), $message->id);
    }

    private function deliver(NotificationMessage $message): void
    {
        $cfg = $this->settings->value(app(EmailSettings::class));
        $context = ['task_id' => $message->task_projection_id, 'outbox_id' => $message->id, 'idempotency_key' => $message->idempotency_key, 'causation_id' => $message->causation_id];
        if ($cfg['provider'] === 'disabled') {
            $message->update(['state' => NotificationMessage::SKIPPED, 'error_code' => 'provider_disabled', 'locked_until' => null,
                'attempt_log' => self::appendAttempt($message, ['outcome' => 'skipped', 'code' => 'provider_disabled', 'message' => 'Envio de e-mail desabilitado nas configurações; mensagem descartada.'])]);
            AuditLogger::record('notification.skipped', 'notification', $message->id, ['code' => 'provider_disabled'], $message->process_instance_id, context: $context);

            return;
        }
        $brand = $this->settings->value(app(BrandingSettings::class));
        $started = microtime(true);
        try {
            $body = in_array($message->event, ['identity.invitation', 'identity.password_reset'], true)
                ? Crypt::decryptString($message->body)
                : $message->body;
            $mail = new OutgoingMail(
                (string) $message->recipient_address,
                $message->recipient_name,
                $message->subject,
                $body,
                $this->emailLayout->render($message->event, $message->subject, $body, (array) ($cfg['layout'] ?? []), $brand),
                (string) ($message->idempotency_key ?? $message->id),
                $message->correlation_id,
            );
            $result = $this->factory->make($cfg)->send($mail);
            $message->update([
                'state' => NotificationMessage::SENT, 'provider' => $result->provider, 'provider_message_id' => mb_substr((string) $result->providerMessageId, 0, 200),
                'sent_at' => now(), 'locked_until' => null, 'last_error' => null, 'error_code' => null,
                'attempt_log' => self::appendAttempt($message, ['outcome' => 'sent', 'provider' => $result->provider, 'durationMs' => $result->durationMs, 'providerMessageId' => mb_substr((string) $result->providerMessageId, 0, 200)]),
            ]);
            AuditLogger::record('notification.sent', 'notification', $message->id, ['event' => $message->event, 'recipient' => $message->recipient, 'provider' => $result->provider, 'durationMs' => $result->durationMs, 'attempt' => $message->attempts], $message->process_instance_id, context: $context);
        } catch (Throwable $e) {
            $err = $e instanceof MailException ? $e : new MailException('provider_error', 'Falha inesperada no envio.', true);
            $this->failure($message, $err, $cfg['provider'], $context, (int) round((microtime(true) - $started) * 1000));
        }
    }

    /** @param array<string,mixed> $context */
    private function failure(NotificationMessage $message, MailException $e, string $provider, array $context, ?int $durationMs = null): void
    {
        $retry = $e->transient && $message->attempts < $message->max_attempts;
        $delayMinutes = min(60, 2 ** max(0, $message->attempts - 1));
        $message->update([
            'attempt_log' => self::appendAttempt($message, [
                'outcome' => $retry ? 'retry' : 'failed', 'provider' => $provider, 'code' => $e->errorCode, 'message' => Masker::error($e->getMessage()),
                'transient' => $e->transient, 'durationMs' => $durationMs, 'nextAttemptAt' => $retry ? now()->addMinutes($delayMinutes)->toIso8601String() : null,
                'reason' => $retry ? null : ($e->transient ? 'tentativas esgotadas ('.$message->attempts.'/'.$message->max_attempts.')' : 'falha permanente (não adianta repetir)'),
            ]),
            'state' => $retry ? NotificationMessage::RETRYING : NotificationMessage::FAILED,
            'provider' => $provider,
            'error_code' => $e->errorCode,
            'last_error' => Masker::error($e->getMessage()),
            'available_at' => $retry ? now()->addMinutes($delayMinutes) : $message->available_at,
            'failed_at' => $retry ? null : now(),
            'locked_until' => null,
        ]);
        AuditLogger::record($retry ? 'notification.retry_scheduled' : 'notification.failed', 'notification', $message->id,
            ['event' => $message->event, 'code' => $e->errorCode, 'attempt' => $message->attempts, 'nextInMinutes' => $retry ? $delayMinutes : null], $message->process_instance_id,
            outcome: 'failure', context: $context);
        if ($retry) {
            SendNotification::dispatch($message->id)->onQueue('notifications')->delay(now()->addMinutes($delayMinutes));
        }
    }

    private function claim(string $id): ?NotificationMessage
    {
        $now = now();
        $updated = NotificationMessage::whereKey($id)
            ->where(fn ($q) => $q->whereIn('state', [NotificationMessage::QUEUED, NotificationMessage::RETRYING])
                ->orWhere(fn ($q2) => $q2->where('state', NotificationMessage::SENDING)->where('locked_until', '<', $now)))
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $now))
            ->update(['state' => NotificationMessage::SENDING, 'locked_until' => $now->copy()->addMinutes(2), 'attempts' => DB::raw('attempts + 1'), 'last_attempt_at' => $now, 'updated_at' => $now]);

        return $updated === 1 ? NotificationMessage::find($id) : null;
    }

    /**
     * Log por tentativa (no máximo 30 entradas): resultado, código sanitizado, duração e próxima tentativa.
     *
     * @param  array<string,mixed>  $entry
     * @return list<array<string,mixed>>
     */
    private static function appendAttempt(NotificationMessage $message, array $entry): array
    {
        $log = $message->attempt_log ?? [];
        $log[] = array_filter(['at' => now()->toIso8601String(), 'attempt' => $message->attempts, ...$entry], fn ($v) => $v !== null);

        return array_slice($log, -30);
    }

    /** Varredura do agendador: mensagens vencidas cujo job se perdeu. */
    public function dispatchDue(int $limit = 50): int
    {
        $due = NotificationMessage::where(fn ($q) => $q->whereIn('state', [NotificationMessage::QUEUED, NotificationMessage::RETRYING])->where(fn ($q2) => $q2->whereNull('available_at')->orWhere('available_at', '<=', now())))
            ->orWhere(fn ($q) => $q->where('state', NotificationMessage::SENDING)->where('locked_until', '<', now()))
            ->orderBy('created_at')->limit($limit)->pluck('id');
        foreach ($due as $id) {
            $this->send((string) $id);
        }

        return $due->count();
    }

    /** Reprocessamento manual de dead-letter (perfil processes.manage). */
    public function retry(NotificationMessage $message, User $by): void
    {
        if (! in_array($message->state, [NotificationMessage::FAILED, NotificationMessage::RETRYING], true)) {
            return;
        }
        $message->update(['state' => NotificationMessage::QUEUED, 'attempts' => 0, 'available_at' => now(), 'failed_at' => null, 'locked_until' => null,
            'attempt_log' => self::appendAttempt($message, ['outcome' => 'manual_retry', 'by' => $by->name, 'message' => 'Reprocessamento manual: contador de tentativas zerado.'])]);
        AuditLogger::record('notification.manual_retry', 'notification', $message->id, ['event' => $message->event], $message->process_instance_id, $by, context: ['outbox_id' => $message->id]);
        SendNotification::dispatch($message->id)->onQueue('notifications')->afterCommit();
    }
}
