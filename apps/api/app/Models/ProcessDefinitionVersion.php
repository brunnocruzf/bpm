<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $version
 * @property string $status
 * @property array<string,mixed> $settings
 * @property string $catalog_id
 * @property string|null $bpmn_xml
 * @property string|null $hash
 * @property array<string,mixed>|null $validation_result
 * @property string|null $engine_deployment_id
 * @property string|null $engine_definition_id
 * @property int $lock_version
 * @property string|null $updated_by
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProcessDefinitionVersion extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_RETIRED = 'retired';

    protected $table = 'process_definition_version';

    protected $fillable = [
        'catalog_id', 'version', 'status', 'bpmn_xml', 'settings', 'hash', 'validation_result',
        'engine_deployment_id', 'engine_definition_id', 'lock_version',
        'created_by', 'updated_by', 'published_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'validation_result' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProcessCatalog, $this> */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(ProcessCatalog::class, 'catalog_id');
    }

    /** @return HasMany<ProcessStageVersion, $this> */
    public function stages(): HasMany
    {
        return $this->hasMany(ProcessStageVersion::class, 'definition_version_id')->orderBy('position');
    }

    /** @return HasMany<ProcessTransitionVersion, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(ProcessTransitionVersion::class, 'definition_version_id')->orderBy('position');
    }

    /** @return HasMany<FormSchemaVersion, $this> */
    public function forms(): HasMany
    {
        return $this->hasMany(FormSchemaVersion::class, 'definition_version_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isDeployed(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->engine_definition_id !== null;
    }
}
