<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schema Form.io versionado e vinculado a UMA etapa de UMA versão do fluxo.
 *
 * @property string $form_key
 * @property array<string,mixed> $schema
 * @property string $id
 * @property string $title
 * @property string|null $hash
 * @property string $status
 */
class FormSchemaVersion extends Model
{
    use HasUuids;

    protected $table = 'form_schema_version';

    protected $fillable = ['definition_version_id', 'stage_version_id', 'form_key', 'title', 'schema', 'renderer_version', 'hash', 'status'];

    protected function casts(): array
    {
        return ['schema' => 'array'];
    }

    /** @return BelongsTo<ProcessStageVersion, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProcessStageVersion::class, 'stage_version_id');
    }

    /** @return BelongsTo<ProcessDefinitionVersion, $this> */
    public function definitionVersion(): BelongsTo
    {
        return $this->belongsTo(ProcessDefinitionVersion::class, 'definition_version_id');
    }

    public static function emptySchema(): array
    {
        return ['display' => 'form', 'components' => []];
    }
}
