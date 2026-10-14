<?php

namespace App\Auth;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Métodos de login disponíveis (simultâneos — ADR-0015). Decisão de PLATAFORMA (env), nunca da tela:
 *  - entra:      sempre que houver configuração Entra (ativada em Configurações ou do ambiente);
 *  - demo:       visitante com usuários fictícios — somente fora de produção;
 *  - superadmin: usuário e senha locais de emergência — desabilitado por padrão; em produção exige
 *                lista de IPs permitidos.
 * O SPA apenas reflete /public/auth-config; quem aceita ou recusa o token é o servidor.
 */
final class AuthMethods
{
    public const ENTRA = 'entra';

    public const DEMO = 'demo';

    public const SUPERADMIN = 'superadmin';
    public const LOCAL = 'local';

    /** Falha cedo (boot) em combinações inseguras. */
    public static function assertSafeConfiguration(): void
    {
        if (! app()->environment('production')) {
            return;
        }
        if (config('bpm.auth.driver') === 'dev' || (bool) config('bpm.auth.demo.enabled')) {
            throw new RuntimeException('BPM_AUTH_DRIVER=dev / BPM_DEMO_LOGIN_ENABLED é proibido em produção (use BPM_DEMO_URL para apontar para o ambiente de demonstração).');
        }
        if ((bool) config('bpm.auth.superadmin.enabled') && ! self::allowedIps()) {
            throw new RuntimeException('BPM_SUPERADMIN_LOGIN_ENABLED em produção exige BPM_SUPERADMIN_ALLOWED_IPS.');
        }
    }

    public static function demoEnabled(): bool
    {
        return ! app()->environment('production')
            && (config('bpm.auth.driver') === 'dev' || (bool) config('bpm.auth.demo.enabled'));
    }

    /** Link externo para um ambiente de demonstração separado (somente http/https). */
    public static function demoUrl(): ?string
    {
        $url = trim((string) config('bpm.auth.demo.url', ''));

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url) ? $url : null;
    }

    /** SuperAdmin habilitado E permitido para o IP da requisição. */
    public static function superAdminEnabled(?Request $request = null): bool
    {
        if (! (bool) config('bpm.auth.superadmin.enabled')) {
            return false;
        }
        $allowed = self::allowedIps();
        if (! $allowed) {
            return true;
        }
        $ip = $request?->ip();

        return $ip !== null && IpUtils::checkIp($ip, $allowed);
    }

    public static function entraEnabled(EntraAuthConfig $config): bool
    {
        if ($config->spaConfig() !== null) {
            return true;
        }
        $env = (array) config('bpm.auth.entra');

        return ! empty($env['tenant_id']) && ! empty($env['audiences']);
    }

    /** @return list<string> */
    private static function allowedIps(): array
    {
        return array_values(array_filter((array) config('bpm.auth.superadmin.allowed_ips', []), fn ($v) => is_string($v) && $v !== ''));
    }
}
