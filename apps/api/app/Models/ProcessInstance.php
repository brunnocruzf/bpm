<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $number
 * @property string $status
 * @property array<string,mixed> $data
 * @property string $catalog_id
 * @property string $definition_version_id
 * @property string|null $engine_process_instance_id
 * @property string $requester_id
 * @property string $requester_name
 * @property string|null $requester_area
 * @property string|null $result
 * @property string|null $end_stage_key
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $synced_at
 */
class ProcessInstance extends Model
{
    use HasUuids;

    public const STARTING = 'starting';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const ERROR = 'error';

    protected $table = 'process_instance';

    protected $fillable = [
        'catalog_id', 'definition_version_id', 'number', 'sequence_year', 'sequence_value', 'engine_process_instance_id',
        'requester_id', 'requester_name', 'requester_area', 'status', 'result', 'end_stage_key', 'data',
        'started_at', 'ended_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProcessCatalog, $this> */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ProcessCatalog::class, 'catalog_id');
    }

    /** @return BelongsTo<ProcessDefinitionVersion, $this> */
    public function definitionVersion(): BelongsTo
    {
        return $this->belongsTo(ProcessDefinitionVersion::class, 'definition_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return HasMany<TaskProjection, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(TaskProjection::class, 'process_instance_id')->orderBy('created_at');
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'process_instance_id')->orderBy('created_at');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'process_instance_id');
    }

    /** @return HasMany<SupplierAction, $this> */
    public function supplierActions(): HasMany
    {
        return $this->hasMany(SupplierAction::class, 'process_instance_id')->orderBy('position');
    }
}
