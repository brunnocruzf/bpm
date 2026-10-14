<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Outbox de notificações (e-mail). Estados: queued → sending → sent | retrying | failed | skipped.
 *
 * @property string $id
 * @property string $event
 * @property string $channel
 * @property string $recipient
 * @property string|null $recipient_address
 * @property string|null $recipient_name
 * @property string|null $recipient_user_id
 * @property string $subject
 * @property string $body
 * @property string $state
 * @property int $attempts
 * @property int $max_attempts
 * @property string|null $last_error
 * @property string|null $error_code
 * @property string|null $process_instance_id
 * @property string|null $task_projection_id
 * @property string|null $idempotency_key
 * @property string|null $correlation_id
 * @property string|null $causation_id
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property Carbon|null $available_at
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property list<array<string,mixed>>|null $attempt_log uma entrada por tentativa (resultado, código, duração, próxima tentativa)
 */
class NotificationMessage extends Model
{
    use HasUuids;

    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const RETRYING = 'retrying';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $table = 'notification_outbox';

    protected $fillable = [
        'event', 'channel', 'recipient', 'recipient_address', 'recipient_name', 'recipient_user_id', 'subject', 'body', 'state', 'attempts',
        'max_attempts', 'last_error', 'error_code', 'process_instance_id', 'task_projection_id', 'idempotency_key', 'correlation_id',
        'causation_id', 'provider', 'provider_message_id', 'available_at', 'locked_until', 'last_attempt_at', 'sent_at', 'failed_at', 'attempt_log',
    ];

    protected $attributes = ['state' => self::QUEUED, 'attempts' => 0, 'channel' => 'mail', 'max_attempts' => 6];

    protected function casts(): array
    {
        return [
            'available_at' => 'datetime', 'locked_until' => 'datetime', 'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime', 'failed_at' => 'datetime', 'attempts' => 'integer', 'max_attempts' => 'integer', 'attempt_log' => 'array',
        ];
    }
}
