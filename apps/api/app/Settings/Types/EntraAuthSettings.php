<?php

namespace App\Settings\Types;

use App\Models\AppSetting;
use App\Models\User;
use App\Settings\SettingType;
use App\Support\ApiProblem;

/**
 * Login OIDC da aplicação (SPA/MSAL + validação de access token no Laravel). Somente parâmetros
 * NÃO secretos: tenant, client ID da SPA, redirect URIs, audience e scope da API. Não há client
 * secret (SPA é cliente público com PKCE). O produto não cria nem edita App Registrations.
 */
class EntraAuthSettings extends SettingType
{
    public const KEY = 'entra.auth';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Microsoft Entra ID — login da aplicação';
    }

    public function requiresActivation(): bool
    {
        return true;
    }

    public function defaults(): array
    {
        return [
            'tenantId' => (string) config('bpm.auth.entra.tenant_id', ''),
            'authorityHost' => 'login.microsoftonline.com',
            'spaClientId' => '',
            'redirectUris' => [],
            'postLogoutRedirectUri' => '',
            'allowedOrigins' => [],
            'apiAudience' => (string) (config('bpm.auth.entra.audiences')[0] ?? ''),
            'apiScope' => (string) config('bpm.auth.entra.required_scope', 'access_as_user'),
            'allowedTenants' => [],
            'checklist' => [
                'spaPlatform' => false,
                'redirectUris' => false,
                'apiExposed' => false,
                'scopeConsented' => false,
                'appRoles' => false,
                'tokenV2' => false,
            ],
        ];
    }

    public function normalize(array $input, array $current, User $by): array
    {
        $errors = [];
        $guid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $value = $current;

        if (array_key_exists('tenantId', $input)) {
            $tenant = strtolower(trim((string) $input['tenantId']));
            if ($tenant !== '' && ! preg_match($guid, $tenant) && ! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $tenant)) {
                $errors['tenantId'][] = 'Informe o Tenant ID (GUID) ou o domínio verificado do tenant.';
            }
            if (in_array($tenant, ['common', 'organizations', 'consumers'], true)) {
                $errors['tenantId'][] = 'Use o tenant da TMSA; endpoints multi-tenant não são aceitos.';
            }
            $value['tenantId'] = $tenant;
        }
        if (array_key_exists('authorityHost', $input)) {
            $host = strtolower(trim((string) $input['authorityHost']));
            if (! in_array($host, (array) config('bpm.directory.authority_hosts'), true)) {
                $errors['authorityHost'][] = 'Host de identidade não suportado.';
            }
            $value['authorityHost'] = $host;
        }
        if (array_key_exists('spaClientId', $input)) {
            $id = strtolower(trim((string) $input['spaClientId']));
            if ($id !== '' && ! preg_match($guid, $id)) {
                $errors['spaClientId'][] = 'Client ID deve ser um GUID (Application ID da SPA).';
            }
            $value['spaClientId'] = $id;
        }
        foreach (['redirectUris', 'allowedOrigins'] as $field) {
            if (array_key_exists($field, $input)) {
                $list = array_values(array_unique(array_filter(array_map(fn ($u) => rtrim(trim((string) $u), '/'), (array) $input[$field]))));
                foreach ($list as $u) {
                    if (! self::isSafeUri($u, $field === 'allowedOrigins')) {
                        $errors[$field][] = "Endereço inválido: $u (use https; http apenas para localhost).";
                    }
                }
                if (count($list) > 10) {
                    $errors[$field][] = 'No máximo 10 endereços.';
                }
                $value[$field] = $list;
            }
        }
        if (array_key_exists('postLogoutRedirectUri', $input)) {
            $u = rtrim(trim((string) $input['postLogoutRedirectUri']), '/');
            if ($u !== '' && ! self::isSafeUri($u, false)) {
                $errors['postLogoutRedirectUri'][] = 'Endereço inválido (https; http apenas para localhost).';
            }
            $value['postLogoutRedirectUri'] = $u;
        }
        if (array_key_exists('apiAudience', $input)) {
            $aud = trim((string) $input['apiAudience']);
            if ($aud !== '' && ! preg_match($guid, $aud) && ! preg_match('#^api://[A-Za-z0-9.\-/]{1,200}$#', $aud) && ! preg_match('#^https://[A-Za-z0-9.\-/]{1,200}$#', $aud)) {
                $errors['apiAudience'][] = 'Audience deve ser o Application ID URI (api://...) ou o client ID da API.';
            }
            $value['apiAudience'] = $aud;
        }
        if (array_key_exists('apiScope', $input)) {
            $scope = trim((string) $input['apiScope']);
            if (! preg_match('/^[A-Za-z0-9_.\-]{1,80}$/', $scope)) {
                $errors['apiScope'][] = 'Scope delegado inválido (ex.: access_as_user).';
            }
            $value['apiScope'] = $scope;
        }
        if (array_key_exists('allowedTenants', $input)) {
            $tenants = array_values(array_unique(array_filter(array_map(fn ($t) => strtolower(trim((string) $t)), (array) $input['allowedTenants']))));
            foreach ($tenants as $t) {
                if (! preg_match($guid, $t)) {
                    $errors['allowedTenants'][] = "Tenant permitido deve ser GUID: $t";
                }
            }
            $value['allowedTenants'] = $tenants;
        }
        if (isset($input['checklist']) && is_array($input['checklist'])) {
            foreach (array_keys($this->defaults()['checklist']) as $item) {
                if (array_key_exists($item, $input['checklist'])) {
                    $value['checklist'][$item] = (bool) $input['checklist'][$item];
                }
            }
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $value;
    }

    public function statusAfterSave(array $value): array
    {
        $missing = $this->missing($value);
        if ($missing) {
            return [AppSetting::INCOMPLETE, 'Faltam: '.implode(', ', $missing).'.'];
        }

        return [AppSetting::TEST_PENDING, 'Execute a validação (descoberta OIDC) e o teste de login/token.'];
    }

    /** @return list<string> */
    public function missing(array $value): array
    {
        $labels = ['tenantId' => 'Tenant ID', 'spaClientId' => 'Client ID da SPA', 'apiAudience' => 'Audience da API', 'apiScope' => 'Scope da API'];
        $missing = [];
        foreach ($labels as $field => $label) {
            if (empty($value[$field])) {
                $missing[] = $label;
            }
        }
        if (empty($value['redirectUris'])) {
            $missing[] = 'Redirect URI';
        }

        return $missing;
    }

    public static function isSafeUri(string $uri, bool $originOnly): bool
    {
        $parts = parse_url($uri);
        if (! $parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if ($originOnly && (isset($parts['path']) && $parts['path'] !== '' || isset($parts['query']))) {
            return false;
        }
        $local = in_array($parts['host'], ['localhost', '127.0.0.1'], true);

        return $parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && $local);
    }

    /** Escopo completo pedido pela SPA (ex.: api://bpm-tmsa-api/access_as_user). */
    public static function fullScope(array $value): string
    {
        return rtrim((string) $value['apiAudience'], '/').'/'.$value['apiScope'];
    }
}
