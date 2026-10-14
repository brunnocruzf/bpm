<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Fluxo exibido no catálogo. Contém versões; cada versão contém etapas.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string $category
 * @property string|null $description
 * @property string $status
 * @property string $instance_prefix
 * @property array<int,string>|null $initiator_groups
 * @property string|null $current_published_version_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProcessCatalog extends Model
{
    use HasUuids;

    protected $table = 'process_catalog';

    protected $fillable = ['key', 'name', 'category', 'description', 'status', 'instance_prefix', 'initiator_groups', 'current_published_version_id', 'created_by'];

    protected function casts(): array
    {
        return ['initiator_groups' => 'array'];
    }

    /** @return HasMany<ProcessDefinitionVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProcessDefinitionVersion::class, 'catalog_id')->orderBy('version');
    }

    /** @return BelongsTo<ProcessDefinitionVersion, $this> */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ProcessDefinitionVersion::class, 'current_published_version_id');
    }

    public function latestVersion(): ?ProcessDefinitionVersion
    {
        return $this->versions()->reorder('version', 'desc')->first();
    }

    public function draftVersion(): ?ProcessDefinitionVersion
    {
        return $this->versions()->where('status', ProcessDefinitionVersion::STATUS_DRAFT)->reorder('version', 'desc')->first();
    }
}
