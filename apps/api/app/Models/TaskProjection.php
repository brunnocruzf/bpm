<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Projeção local da tarefa do Flowable para inbox/consulta. A verdade de
 * execução é o engine; a projeção é mantida por outbox + reconciliador.
 *
 * @property string $id
 * @property string $status
 * @property string|null $candidate_group
 * @property string|null $assignee_id
 * @property int $row_version
 * @property string $process_instance_id
 * @property string|null $engine_task_id
 * @property string $stage_version_id
 * @property string $stage_key
 * @property string $name
 * @property string|null $completed_by
 * @property Carbon|null $due_at
 * @property Carbon|null $engine_created_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TaskProjection extends Model
{
    use HasUuids;

    public const OPEN = 'open';

    public const CLAIMED = 'claimed';

    public const COMPLETING = 'completing';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const ACTIVE = [self::OPEN, self::CLAIMED, self::COMPLETING];

    protected $table = 'task_projection';

    protected $fillable = [
        'process_instance_id', 'engine_task_id', 'stage_version_id', 'stage_key', 'name', 'candidate_group', 'assignee_id',
        'status', 'due_at', 'engine_created_at', 'claimed_at', 'completed_at', 'completed_by', 'row_version',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'engine_created_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'row_version' => 'integer',
        ];
    }

    /** @return BelongsTo<ProcessInstance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(ProcessInstance::class, 'process_instance_id');
    }

    /** @return BelongsTo<ProcessStageVersion, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProcessStageVersion::class, 'stage_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
