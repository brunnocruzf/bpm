<?php

namespace App\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Permissões globais (Gates) derivadas dos papéis do usuário (config bpm.permissions).
 * Negar por padrão: permissão inexistente ou papel ausente = negado. Esconder links no
 * frontend é apenas conveniência; a decisão é sempre tomada aqui.
 */
final class Permissions
{
    public const SETTINGS_MANAGE = 'settings.manage';

    public const PROCESSES_MANAGE = 'processes.manage';

    public const AUDIT_READ = 'audit.read';

    public const AUDIT_EXPORT = 'audit.export';

    public const REPORTS_INSTANCE = 'reports.instance';

    public const REPORTS_OPERATIONAL = 'reports.operational';

    public const REPORTS_TEMPLATES = 'reports.templates';

    public const DATA_SENSITIVE = 'data.sensitive';

    public static function register(): void
    {
        foreach (array_keys((array) config('bpm.permissions', [])) as $ability) {
            Gate::define($ability, fn (User $user) => self::allows($user, $ability));
        }
    }

    public static function allows(?User $user, string $ability): bool
    {
        if (! $user || $user->is_active === false) {
            return false;
        }
        // a chave contém ponto (ex.: settings.manage): não usar notação de ponto do config()
        $roles = (array) (((array) config('bpm.permissions', []))[$ability] ?? []);

        return $roles !== [] && $user->hasRole(...$roles);
    }

    /** @return array<string,bool> mapa para o frontend em camelCase (settingsManage, auditRead...) — apenas apresentação */
    public static function for(User $user): array
    {
        $out = [];
        foreach (array_keys((array) config('bpm.permissions', [])) as $ability) {
            $out[lcfirst(str_replace(' ', '', ucwords(str_replace('.', ' ', $ability))))] = self::allows($user, $ability);
        }

        return $out;
    }
}
