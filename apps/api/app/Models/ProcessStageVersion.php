<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Etapa de uma versão do fluxo. BPMN element_id estável; tarefas humanas têm formulário 1:1.
 *
 * @property string $id
 * @property string $stage_key
 * @property string $element_id
 * @property string $name
 * @property string $stage_type
 * @property string|null $lane
 * @property array<string,mixed> $config
 * @property string $definition_version_id
 * @property int $position
 * @property string|null $description
 * @property string|null $form_schema_version_id
 */
class ProcessStageVersion extends Model
{
    use HasUuids;

    public const START = 'start';

    public const USER_TASK = 'user_task';

    public const EXCLUSIVE_GATEWAY = 'exclusive_gateway';

    public const PARALLEL_GATEWAY = 'parallel_gateway';

    public const SERVICE_TASK = 'service_task';

    public const TIMER = 'timer';

    public const END = 'end';

    public const TYPES = [self::START, self::USER_TASK, self::EXCLUSIVE_GATEWAY, self::PARALLEL_GATEWAY, self::SERVICE_TASK, self::TIMER, self::END];

    protected $table = 'process_stage_version';

    protected $fillable = ['definition_version_id', 'stage_key', 'element_id', 'name', 'stage_type', 'lane', 'position', 'description', 'config', 'form_schema_version_id'];

    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    /** @return BelongsTo<ProcessDefinitionVersion, $this> */
    public function definitionVersion(): BelongsTo
    {
        return $this->belongsTo(ProcessDefinitionVersion::class, 'definition_version_id');
    }

    /** @return BelongsTo<FormSchemaVersion, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(FormSchemaVersion::class, 'form_schema_version_id');
    }

    /** @return HasMany<ProcessTransitionVersion, $this> */
    public function outgoing(): HasMany
    {
        return $this->hasMany(ProcessTransitionVersion::class, 'source_stage_id')->orderBy('position');
    }

    /** @return HasMany<ProcessTransitionVersion, $this> */
    public function incoming(): HasMany
    {
        return $this->hasMany(ProcessTransitionVersion::class, 'target_stage_id');
    }

    public function isHuman(): bool
    {
        return $this->stage_type === self::USER_TASK;
    }

    public function isGateway(): bool
    {
        return in_array($this->stage_type, [self::EXCLUSIVE_GATEWAY, self::PARALLEL_GATEWAY], true);
    }

    public function configValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config ?? [], $key, $default);
    }
}
