<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Usuário espelhado do IdP. Não há senha na tabela: a autenticação é feita pelo Entra ID, pelo acesso
 * de demonstração (usuários fictícios, nunca em produção) ou, para o SuperAdmin de emergência, pela
 * credencial local (local_credentials, somente hash). Chave estável do Entra: tenant + oid.
 * auth_source: entra | demo | superadmin (impede que um método autentique contas de outro).
 *
 * roles/groups são os valores EFETIVOS = união das origens: token (App Roles/grupos do login)
 * e diretório (mapeamentos explícitos da sincronização Graph). Seeds de dev não têm origem.
 *
 * @property string $id
 * @property string $name
 * @property string|null $username
 * @property string|null $email
 * @property string|null $upn
 * @property string|null $area
 * @property string|null $department
 * @property string|null $user_type
 * @property bool|null $account_enabled
 * @property array<int,string> $roles
 * @property array<int,string> $groups
 * @property array<int,string>|null $token_roles
 * @property array<int,string>|null $token_groups
 * @property array<int,string>|null $directory_roles
 * @property array<int,string>|null $directory_groups
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $directory_synced_at
 * @property Carbon|null $disabled_at
 * @property string|null $disabled_reason
 * @property bool|null $is_active
 * @property string|null $entra_oid
 * @property string|null $entra_tid
 * @property string|null $auth_source
 */
class User extends Model implements AuthenticatableContract
{
    use Authenticatable, HasFactory, HasUuids;

    public const SOURCE_ENTRA = 'entra';

    public const SOURCE_DEMO = 'demo';

    public const SOURCE_SUPERADMIN = 'superadmin';

    protected $fillable = [
        'entra_oid', 'entra_tid', 'username', 'name', 'email', 'upn', 'area', 'department', 'user_type', 'account_enabled',
        'roles', 'groups', 'token_roles', 'token_groups', 'directory_roles', 'directory_groups', 'directory_synced_at',
        'is_active', 'disabled_reason', 'disabled_at', 'last_seen_at', 'auth_source',
    ];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'groups' => 'array',
            'token_roles' => 'array',
            'token_groups' => 'array',
            'directory_roles' => 'array',
            'directory_groups' => 'array',
            'is_active' => 'boolean',
            'account_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'directory_synced_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function hasRole(string ...$roleKeys): bool
    {
        $roles = $this->roles ?? [];
        foreach ($roleKeys as $key) {
            $value = config("bpm.roles.$key", $key);
            if (in_array($value, $roles, true)) {
                return true;
            }
        }

        return false;
    }

    public function inGroup(?string $group): bool
    {
        return $group !== null && in_array($group, $this->groups ?? [], true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /** Recalcula roles/groups efetivos a partir das origens (token + diretório). */
    public function recomputeEffectiveAccess(): void
    {
        if ($this->token_roles === null && $this->directory_roles === null && $this->token_groups === null && $this->directory_groups === null) {
            return; // usuário sem origem rastreada (seed de desenvolvimento)
        }
        $this->roles = array_values(array_unique([...($this->token_roles ?? []), ...($this->directory_roles ?? [])]));
        $this->groups = array_values(array_unique([...($this->token_groups ?? []), ...($this->directory_groups ?? [])]));
    }
}
