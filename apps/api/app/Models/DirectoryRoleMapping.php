<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Regra explícita: grupo Entra → grupo/raia TMSA e/ou papéis.
 *
 * @property string $id
 * @property string $entra_group_id
 * @property string|null $entra_group_name
 * @property string|null $tmsa_group_key
 * @property array<int,string> $tmsa_roles
 * @property bool $privileged
 * @property bool $enabled
 * @property string|null $justification
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DirectoryRoleMapping extends Model
{
    use HasUuids;

    protected $table = 'directory_role_mapping';

    protected $fillable = ['entra_group_id', 'entra_group_name', 'tmsa_group_key', 'tmsa_roles', 'privileged', 'enabled', 'justification', 'created_by', 'created_by_name'];

    protected $attributes = ['tmsa_roles' => '[]', 'privileged' => false, 'enabled' => true];

    protected function casts(): array
    {
        return ['tmsa_roles' => 'array', 'privileged' => 'boolean', 'enabled' => 'boolean'];
    }
}
