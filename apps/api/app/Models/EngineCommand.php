<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Outbox de comandos para o engine. Gravado na mesma transação local da
 * submissão/auditoria; consumido pelo worker com reconciliação.
 *
 * @property string $id
 * @property string $command
 * @property string $state
 * @property int $attempts
 * @property array<string,mixed> $payload
 * @property string|null $process_instance_id
 * @property string|null $task_projection_id
 * @property string|null $definition_version_id
 * @property string|null $correlation_id
 * @property string|null $causation_id
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $locked_until
 * @property Carbon|null $available_at
 */
class EngineCommand extends Model
{
    use HasUuids;

    public const DEPLOY = 'DEPLOY';

    public const START = 'START';

    public const COMPLETE = 'COMPLETE';

    public const PENDING = 'PENDING';

    public const SENDING = 'SENDING';

    public const CONFIRMED = 'CONFIRMED';

    public const RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';

    public const FAILED = 'FAILED';

    protected $table = 'engine_command_outbox';

    protected $fillable = ['causation_id',
        'command', 'idempotency_key', 'definition_version_id', 'process_instance_id', 'task_projection_id', 'payload', 'state',
        'attempts', 'correlation_id', 'last_error', 'response', 'available_at', 'locked_until', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_until' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}
