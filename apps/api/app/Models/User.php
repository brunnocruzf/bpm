<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Usuário espelhado do IdP. Não há senha na tabela: a autenticação é feita pelo Entra ID, pelo acesso
 * de demonstração (usuários fictícios, nunca em produção), por conta local provisionada pelo administrador,
 * ou pelo SuperAdmin de emergência. Credenciais locais ficam separadas em local_credentials. Chave Entra: tenant + oid.
 * auth_source impede que um método autentique contas de outra origem.
 *
 * roles/groups são os valores efetivos: roles vêm do token e dos mapeamentos Graph; groups também incluem
 * associações manuais internas, armazenadas separadamente das memberships Entra.
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
 * @property array<int,string>|null $manual_groups
 * @property array<int,string>|null $manual_roles
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
 * @property string|null $manager_entra_oid
 * @property string|null $manager_user_id
 */
class User extends Model implements AuthenticatableContract
{
    use Authenticatable, HasFactory, HasUuids;

    public const SOURCE_ENTRA = 'entra';

    public const SOURCE_DEMO = 'demo';

    public const SOURCE_SUPERADMIN = 'superadmin';

    public const SOURCE_LOCAL = 'local';

    protected $fillable = [
        'entra_oid', 'entra_tid', 'manager_entra_oid', 'manager_user_id', 'username', 'name', 'email', 'upn', 'area', 'department', 'user_type', 'account_enabled',
        'roles', 'groups', 'manual_groups', 'manual_roles', 'token_roles', 'token_groups', 'directory_roles', 'directory_groups', 'directory_synced_at',
        'is_active', 'disabled_reason', 'disabled_at', 'last_seen_at', 'auth_source',
    ];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'groups' => 'array',
            'manual_groups' => 'array',
            'manual_roles' => 'array',
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

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_user_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_user_id');
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
            $this->roles = array_values(array_unique([...($this->roles ?? []), ...($this->manual_roles ?? [])]));
            if ($this->manual_groups) {
                $this->groups = array_values(array_unique([...($this->groups ?? []), ...$this->manual_groups]));
            }

            return; // usuário sem origem rastreada (seed de desenvolvimento)
        }
        $this->roles = array_values(array_unique([...($this->token_roles ?? []), ...($this->directory_roles ?? []), ...($this->manual_roles ?? [])]));
        $this->groups = array_values(array_unique([...($this->token_groups ?? []), ...($this->directory_groups ?? []), ...($this->manual_groups ?? [])]));
    }
}
