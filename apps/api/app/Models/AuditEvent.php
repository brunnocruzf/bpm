<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Trilha de auditoria append-only (modelo bloqueia update/delete; trigger no banco reforça).
 *
 * @property string $id
 * @property string $action
 * @property string|null $category
 * @property string|null $outcome
 * @property string|null $actor_id
 * @property string|null $actor_type
 * @property string|null $actor_name
 * @property string|null $actor_oid
 * @property string|null $actor_upn
 * @property string $entity_type
 * @property string|null $entity_id
 * @property string|null $object_name
 * @property string|null $process_instance_id
 * @property string|null $flow_key
 * @property int|null $definition_version
 * @property string|null $engine_process_instance_id
 * @property string|null $instance_number
 * @property string|null $stage_key
 * @property string|null $task_id
 * @property string|null $form_schema_version_id
 * @property string|null $correlation_id
 * @property string|null $causation_id
 * @property string|null $idempotency_key
 * @property string|null $outbox_id
 * @property string|null $operation
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string,mixed> $data
 * @property array<string,mixed>|null $diff
 * @property Carbon $occurred_at
 */
class AuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'audit_event';

    protected $fillable = [
        'organization_key', 'category', 'action', 'outcome', 'actor_id', 'actor_type', 'actor_name', 'actor_oid', 'actor_upn',
        'entity_type', 'entity_id', 'object_name', 'process_instance_id', 'flow_key', 'definition_version', 'engine_process_instance_id',
        'instance_number', 'stage_key', 'task_id', 'form_schema_version_id', 'correlation_id', 'causation_id', 'idempotency_key',
        'outbox_id', 'operation', 'ip_address', 'user_agent', 'data', 'diff', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'diff' => 'array', 'occurred_at' => 'datetime', 'definition_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos de auditoria são append-only.'));
        static::deleting(fn () => throw new LogicException('Eventos de auditoria são append-only.'));
    }
}
