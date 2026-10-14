<?php

namespace App\Notifications;

use App\Jobs\SendNotification;
use App\Models\BpmGroup;
use App\Models\NotificationMessage;
use App\Models\ProcessInstance;
use App\Models\TaskProjection;
use App\Models\User;
use App\Settings\SettingsRepository;
use App\Settings\Types\BrandingSettings;
use App\Settings\Types\EmailSettings;
use App\Support\AuditLogger;
use App\Support\Correlation;

/**
 * Enfileira notificações em notification_outbox (na transação local corrente) com chave de
 * idempotência; o envio é sempre assíncrono (fila "notifications"), nunca bloqueia a submissão.
 * Notificação não é mecanismo de autorização: o link leva ao sistema, que valida o acesso.
 */
class Notifier
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Etapa recebeu uma tarefa (criada pelo engine — já confirmada — ou reatribuída).
     * Envia somente se a etapa de DESTINO habilitou "Enviar e-mail ao responsável".
     *
     * @return list<NotificationMessage>
     */
    public function taskAssigned(TaskProjection $task, string $reason = 'created', ?string $causationId = null): array
    {
        $stage = $task->stage;
        $cfg = (array) ($stage?->configValue('notifyAssignee') ?? []);
        if (! $stage || empty($cfg['enabled'])) {
            return [];
        }
        if ($reason === 'reassigned' && empty($cfg['onReassign'])) {
            return [];
        }
        $instance = $task->instance()->with('catalog')->first();
        if (! $instance) {
            return [];
        }
        $recipients = $this->recipients->forTask($task, (string) ($cfg['groupPolicy'] ?? 'members'), $reason === 'reassigned' ? $task->assignee_id : null);
        $baseKey = "task.assigned:{$instance->id}:{$task->id}";
        if ($recipients === []) {
            return [$this->enqueue([
                'idempotency_key' => "$baseKey:none",
                'event' => 'task.assigned', 'recipient' => 'none', 'recipient_address' => null, 'recipient_name' => null,
                'subject' => 'Sem destinatário elegível', 'body' => '', 'state' => NotificationMessage::SKIPPED,
                'error_code' => 'no_recipient', 'process_instance_id' => $instance->id, 'task_projection_id' => $task->id,
            ], $causationId)];
        }

        $group = BpmGroup::where('key', $task->candidate_group)->value('name') ?? $task->candidate_group ?? '—';
        $vars = [
            'organizacao' => $this->brand()['orgName'],
            'fluxo' => $instance->catalog->name,
            'protocolo' => $instance->number,
            'etapa' => $task->name,
            'grupo' => $group,
            'prazo' => $task->due_at ? $task->due_at->setTimezone((string) config('bpm.sequences.timezone'))->format('d/m/Y H:i') : 'não definido',
            'link' => $this->url("/tasks/{$task->id}"),
        ];
        $template = NotificationTemplate::validate($cfg['subject'] ?? null, $cfg['body'] ?? null);

        $out = [];
        foreach ($recipients as $r) {
            $vars['responsavel'] = $r['name'];
            $out[] = $this->enqueue([
                'idempotency_key' => "$baseKey:{$r['key']}",
                'event' => 'task.assigned',
                'recipient' => $r['key'],
                'recipient_user_id' => $r['userId'],
                'recipient_address' => $r['address'],
                'recipient_name' => $r['name'],
                'subject' => NotificationTemplate::render($template['subject'], $vars, true),
                'body' => NotificationTemplate::render($template['body'], $vars),
                'process_instance_id' => $instance->id,
                'task_projection_id' => $task->id,
            ], $causationId);
        }

        return $out;
    }

    /** @param list<string> $groups */
    public function stageCompleted(TaskProjection $task, array $groups): void
    {
        $instance = $task->instance;
        foreach ($groups as $group) {
            $this->toGroup(
                $group,
                'stage.completed',
                "[{$this->brand()['orgName']}] {$instance->number}: etapa \"{$task->name}\" concluída",
                "A etapa \"{$task->name}\" da solicitação {$instance->number} ({$instance->catalog->name}) foi concluída.\n\nAcompanhe (login necessário): ".$this->url("/instances/{$instance->id}"),
                $instance->id,
                "stage.completed:{$task->id}:group:$group",
            );
        }
    }

    /** @param list<string> $groups */
    public function instanceEnded(ProcessInstance $instance, array $groups): void
    {
        $subject = "[{$this->brand()['orgName']}] {$instance->number} encerrada";
        $body = "A solicitação {$instance->number} ({$instance->catalog->name}) foi encerrada.\n\nConsulte (login necessário): ".$this->url("/instances/{$instance->id}");
        foreach ($groups as $group) {
            $this->toGroup($group, 'instance.ended', $subject, $body, $instance->id, "instance.ended:{$instance->id}:group:$group");
        }
        $requester = User::find($instance->requester_id);
        $active = $requester && $requester->is_active !== false && $requester->email;
        $this->enqueue([
            'idempotency_key' => "instance.ended:{$instance->id}:user:{$instance->requester_id}",
            'event' => 'instance.ended', 'recipient' => 'user:'.$instance->requester_id, 'recipient_user_id' => $instance->requester_id,
            'recipient_address' => $active ? $requester->email : null, 'recipient_name' => $requester?->name,
            'subject' => $subject, 'body' => $body, 'state' => $active ? NotificationMessage::QUEUED : NotificationMessage::SKIPPED,
            'error_code' => $active ? null : 'no_recipient', 'process_instance_id' => $instance->id,
        ]);
    }

    public function toGroup(string $groupKey, string $event, string $subject, string $body, ?string $instanceId, ?string $key = null): void
    {
        $group = BpmGroup::where('key', $groupKey)->first();
        $this->enqueue([
            'idempotency_key' => $key,
            'event' => $event,
            'recipient' => 'group:'.$groupKey,
            'recipient_address' => $group?->email,
            'recipient_name' => $group?->name,
            'subject' => mb_substr($subject, 0, 200),
            'body' => $body,
            'state' => $group?->email ? NotificationMessage::QUEUED : NotificationMessage::SKIPPED,
            'error_code' => $group?->email ? null : 'no_recipient',
            'process_instance_id' => $instanceId,
        ]);
    }

    /** Alerta operacional para administradores ativos (credencial inválida, trava de segurança...). */
    public function adminAlert(string $key, string $subject, string $body): void
    {
        $admins = User::where('is_active', true)->whereNotNull('email')->get()->filter(fn (User $u) => $u->isAdmin());
        foreach ($admins as $admin) {
            $this->enqueue([
                'idempotency_key' => "alert:$key:user:{$admin->id}", 'event' => 'admin.alert', 'recipient' => 'user:'.$admin->id,
                'recipient_user_id' => $admin->id, 'recipient_address' => $admin->email, 'recipient_name' => $admin->name,
                'subject' => mb_substr($subject, 0, 200), 'body' => $body,
            ]);
        }
    }

    /** E-mail de teste para o endereço informado pelo administrador (ambiente de teste). */
    public function testEmail(string $address, User $by, string $subject, string $body): NotificationMessage
    {
        $message = $this->enqueue([
            'idempotency_key' => 'test:'.Correlation::id().':'.substr(hash('sha256', strtolower($address)), 0, 16),
            'event' => 'email.test', 'recipient' => 'test', 'recipient_address' => strtolower($address), 'recipient_name' => null,
            'subject' => mb_substr($subject, 0, 200), 'body' => $body, 'max_attempts' => 1,
        ]);
        AuditLogger::record('notification.test_sent', 'notification', $message->id, ['provider' => $this->settings->value(app(EmailSettings::class))['provider']], null, $by);

        return $message;
    }

    /** @param array<string,mixed> $attrs */
    private function enqueue(array $attrs, ?string $causationId = null): NotificationMessage
    {
        $key = $attrs['idempotency_key'] ?? null;
        if ($key && ($existing = NotificationMessage::where('idempotency_key', $key)->first())) {
            return $existing; // mesma instância + tarefa + destinatário + tipo: não duplica
        }
        $message = NotificationMessage::create([
            'channel' => 'mail',
            'state' => NotificationMessage::QUEUED,
            'available_at' => now(),
            'correlation_id' => Correlation::id(),
            'causation_id' => $causationId,
            'max_attempts' => (int) config('bpm.mail.max_attempts', 6),
            ...$attrs,
        ]);
        AuditLogger::record($message->state === NotificationMessage::SKIPPED ? 'notification.skipped' : 'notification.queued', 'notification', $message->id,
            ['event' => $message->event, 'recipient' => $message->recipient, 'code' => $message->error_code], $message->process_instance_id,
            context: ['task_id' => $message->task_projection_id, 'idempotency_key' => $key, 'outbox_id' => $message->id, 'causation_id' => $causationId]);
        if ($message->state === NotificationMessage::QUEUED) {
            SendNotification::dispatch($message->id)->onQueue('notifications')->afterCommit();
        }

        return $message;
    }

    /** @return array<string,mixed> */
    private function brand(): array
    {
        return $this->settings->value(app(BrandingSettings::class));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/').$path;
    }
}
