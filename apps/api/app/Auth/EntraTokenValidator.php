<?php

namespace App\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;

/**
 * Valida access tokens do Microsoft Entra ID (resource server).
 * Verifica assinatura (JWKS), issuer, audience, tenant, expiração e scope/roles.
 * Nunca aceita ID token como bearer da API (exige scope delegado da API e audience da API).
 * A configuração vem de EntraAuthConfig (ativa na tela de Configurações ou do ambiente).
 */
class EntraTokenValidator
{
    public function __construct(private readonly EntraAuthConfig $config) {}

    /**
     * @param  array<string,mixed>|null  $cfg  configuração candidata (teste de login antes de ativar)
     * @return array<string,mixed> claims validadas
     */
    public function validate(string $jwt, ?array $cfg = null): array
    {
        $cfg ??= $this->config->effective();
        $tenant = (string) ($cfg['tenant_id'] ?? '');
        if ($tenant === '' || empty($cfg['audiences'])) {
            throw new RuntimeException('Entra ID não configurado (tenant/audience).');
        }
        $host = (string) ($cfg['authority_host'] ?? 'login.microsoftonline.com');

        JWT::$leeway = (int) ($cfg['leeway_seconds'] ?? 60);
        $claims = (array) JWT::decode($jwt, $this->keys($tenant, $host, $cfg));

        $tid = (string) ($claims['tid'] ?? '');
        if (! in_array($tid, (array) ($cfg['allowed_tenants'] ?? []), true)) {
            throw new RuntimeException('Tenant não autorizado.');
        }

        $issuers = ["https://$host/$tid/v2.0", "https://sts.windows.net/$tid/"];
        if (! in_array($claims['iss'] ?? null, $issuers, true)) {
            throw new RuntimeException('Issuer inválido.');
        }

        $aud = $claims['aud'] ?? null;
        $audList = is_array($aud) ? $aud : [$aud];
        if (array_intersect($audList, (array) $cfg['audiences']) === []) {
            throw new RuntimeException('Audience inválida.');
        }

        // Access token delegado precisa do scope da API; token app-only é recusado (sem usuário).
        $scopes = explode(' ', (string) ($claims['scp'] ?? ''));
        $required = (string) ($cfg['required_scope'] ?? '');
        if ($required !== '' && ! in_array($required, $scopes, true)) {
            throw new RuntimeException('Scope ausente.');
        }
        if (empty($claims['oid'])) {
            throw new RuntimeException('Token sem identidade de usuário.');
        }

        return array_map(fn ($v) => $v instanceof stdClass ? (array) $v : $v, $claims);
    }

    /**
     * @param  array<string,mixed>  $cfg
     * @return array<string,Key>
     */
    private function keys(string $tenant, string $host, array $cfg): array
    {
        $url = ($cfg['jwks_url'] ?? null) ?: "https://$host/$tenant/discovery/v2.0/keys";
        if (! str_starts_with((string) $url, 'https://'.$host.'/') && ! app()->runningUnitTests()) {
            throw new RuntimeException('JWKS fora do host de identidade configurado.');
        }
        $ttl = (int) ($cfg['jwks_cache_seconds'] ?? 3600);

        $jwks = Cache::remember('bpm:entra:jwks:'.md5((string) $url), $ttl, function () use ($url) {
            $response = Http::timeout(5)->connectTimeout(3)->get($url);
            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new RuntimeException('Falha ao obter JWKS do Entra ID.');
            }

            return $response->json();
        });

        return JWK::parseKeySet($jwks, 'RS256');
    }
}
