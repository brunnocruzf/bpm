<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sequence flow BPMN. A condição é uma DSL em lista branca (ver ConditionCompiler).
 *
 * @property array<string,mixed>|null $condition
 * @property string $id
 * @property string $element_id
 * @property string $source_stage_id
 * @property string $target_stage_id
 * @property string|null $label
 * @property bool $is_default
 * @property int $position
 */
class ProcessTransitionVersion extends Model
{
    use HasUuids;

    protected $table = 'process_transition_version';

    protected $fillable = ['definition_version_id', 'element_id', 'source_stage_id', 'target_stage_id', 'label', 'condition', 'is_default', 'position'];

    protected function casts(): array
    {
        return ['condition' => 'array', 'is_default' => 'boolean'];
    }

    /** @return BelongsTo<ProcessStageVersion, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ProcessStageVersion::class, 'source_stage_id');
    }

    /** @return BelongsTo<ProcessStageVersion, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(ProcessStageVersion::class, 'target_stage_id');
    }
}
