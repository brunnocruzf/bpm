<?php

namespace App\Auth;

use App\Models\AppSetting;
use App\Settings\SettingsRepository;
use App\Settings\Types\EntraAuthSettings;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Configuração efetiva do login Entra: a configuração da tela só passa a valer depois de
 * validada (descoberta OIDC + teste de token) e ativada; até lá vale a do ambiente (env).
 * O driver de autenticação (dev/entra) continua sendo decisão de plataforma (env).
 */
class EntraAuthConfig
{
    private const CACHE_KEY = 'bpm:entra-auth:active';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array<string,mixed> no formato de config('bpm.auth.entra') */
    public function effective(): array
    {
        $setting = $this->activeSetting();

        return $setting ? self::fromSetting($setting->value, $setting->last_validation) : self::fromEnv();
    }

    public function source(): string
    {
        return $this->activeSetting() ? 'settings' : 'environment';
    }

    /** Parâmetros públicos para a SPA (MSAL). Nenhum segredo. */
    public function spaConfig(): ?array
    {
        $setting = $this->activeSetting();
        if (! $setting) {
            return null;
        }
        $v = $setting->value;

        return [
            'clientId' => $v['spaClientId'],
            'authority' => 'https://'.$v['authorityHost'].'/'.$v['tenantId'],
            'scopes' => [EntraAuthSettings::fullScope($v)],
            'postLogoutRedirectUri' => $v['postLogoutRedirectUri'] ?: null,
        ];
    }

    /**
     * @param  array<string,mixed>  $value
     * @param  array<string,mixed>|null  $validation
     * @return array<string,mixed>
     */
    public static function fromSetting(array $value, ?array $validation = null): array
    {
        $tenantGuid = (string) ($validation['tenantGuid'] ?? $value['tenantId'] ?? '');
        $host = (string) ($value['authorityHost'] ?? 'login.microsoftonline.com');

        return [
            'tenant_id' => $tenantGuid,
            'authority_host' => $host,
            'allowed_tenants' => ($value['allowedTenants'] ?? []) ?: [$tenantGuid],
            'audiences' => array_filter([(string) ($value['apiAudience'] ?? '')]),
            'jwks_url' => $validation['jwksUri'] ?? "https://$host/$tenantGuid/discovery/v2.0/keys",
            'jwks_cache_seconds' => (int) config('bpm.auth.entra.jwks_cache_seconds', 3600),
            'leeway_seconds' => 60,
            'area_claim' => (string) config('bpm.auth.entra.area_claim', 'department'),
            'required_scope' => (string) ($value['apiScope'] ?? 'access_as_user'),
        ];
    }

    /** @return array<string,mixed> */
    public static function fromEnv(): array
    {
        return [...(array) config('bpm.auth.entra'), 'authority_host' => 'login.microsoftonline.com'];
    }

    private function activeSetting(): ?AppSetting
    {
        try {
            $data = Cache::remember(self::CACHE_KEY, 30, function () {
                $setting = $this->settings->get(EntraAuthSettings::KEY);

                return $setting->exists && $setting->activated_at && $setting->status === AppSetting::CONFIGURED
                    ? ['value' => $setting->value, 'last_validation' => $setting->last_validation]
                    : false;
            });
        } catch (Throwable) {
            return null; // banco indisponível: mantém a configuração do ambiente
        }

        return $data ? new AppSetting(['value' => $data['value'], 'last_validation' => $data['last_validation']]) : null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
