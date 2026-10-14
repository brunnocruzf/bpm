<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Grupo do Entra importado (chave: tenant + object id).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $object_id
 * @property string|null $display_name
 * @property string|null $mail
 * @property string|null $description
 * @property bool|null $security_enabled
 * @property Carbon|null $removed_at
 * @property Carbon|null $synced_at
 */
class DirectoryGroup extends Model
{
    use HasUuids;

    protected $table = 'directory_group';

    protected $fillable = ['tenant_id', 'object_id', 'display_name', 'mail', 'description', 'security_enabled', 'removed_at', 'synced_at'];

    protected function casts(): array
    {
        return ['security_enabled' => 'boolean', 'removed_at' => 'datetime', 'synced_at' => 'datetime'];
    }

    /** @return HasMany<DirectoryMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(DirectoryMembership::class, 'directory_group_id');
    }
}
