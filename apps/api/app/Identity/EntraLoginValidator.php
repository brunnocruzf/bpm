<?php

namespace App\Identity;

use App\Auth\EntraAuthConfig;
use App\Auth\EntraTokenValidator;
use App\Settings\Types\EntraAuthSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Validação real da configuração de login (nada de "sucesso fixo"):
 *  1. descoberta OIDC do tenant (issuer, jwks_uri, endpoints) e coerência com a configuração;
 *  2. teste de token: a SPA faz login com a configuração candidata e envia o access token,
 *     validado aqui com a mesma regra do resource server (assinatura, iss, aud, tid, scp, exp).
 * Nenhum token é persistido ou registrado; só o resultado sanitizado.
 */
class EntraLoginValidator
{
    public function __construct(private readonly EntraTokenValidator $tokens) {}

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public function discover(array $value): array
    {
        $checks = [];
        $missing = (new EntraAuthSettings)->missing($value);
        if ($missing) {
            return ['ok' => false, 'step' => 'config', 'code' => 'incomplete', 'message' => 'Configuração incompleta: '.implode(', ', $missing).'.', 'checks' => []];
        }
        $host = (string) $value['authorityHost'];
        $tenant = (string) $value['tenantId'];
        $url = "https://$host/$tenant/v2.0/.well-known/openid-configuration";
        $started = microtime(true);
        try {
            $response = Http::timeout(8)->connectTimeout(4)->acceptJson()->get($url);
        } catch (ConnectionException) {
            return ['ok' => false, 'step' => 'discovery', 'code' => 'unreachable', 'message' => "Não foi possível acessar $host (rede/egress).", 'checks' => []];
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        if ($response->status() === 400 || $response->status() === 404) {
            return ['ok' => false, 'step' => 'discovery', 'code' => 'tenant_not_found', 'message' => 'Tenant não encontrado no host de identidade informado.', 'checks' => [], 'durationMs' => $ms];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'step' => 'discovery', 'code' => 'discovery_http_'.$response->status(), 'message' => 'Descoberta OIDC respondeu HTTP '.$response->status().'.', 'checks' => [], 'durationMs' => $ms];
        }
        $doc = (array) $response->json();
        $issuer = (string) ($doc['issuer'] ?? '');
        $tenantGuid = preg_match('#^https://[^/]+/([0-9a-f\-]{36})/v2\.0$#i', $issuer, $m) ? strtolower($m[1]) : null;
        $checks[] = ['name' => 'Documento de descoberta OIDC', 'ok' => true];
        $checks[] = ['name' => 'Issuer do tenant (v2.0)', 'ok' => $tenantGuid !== null && str_starts_with($issuer, "https://$host/")];
        $jwksUri = (string) ($doc['jwks_uri'] ?? '');
        $jwksOk = false;
        if (str_starts_with($jwksUri, "https://$host/")) {
            try {
                $jwks = Http::timeout(8)->connectTimeout(4)->get($jwksUri);
                $jwksOk = $jwks->successful() && count((array) $jwks->json('keys')) > 0;
            } catch (ConnectionException) {
                $jwksOk = false;
            }
        }
        $checks[] = ['name' => 'Chaves de assinatura (JWKS) acessíveis', 'ok' => $jwksOk];
        $checks[] = ['name' => 'Fluxo Authorization Code + PKCE suportado', 'ok' => in_array('S256', (array) ($doc['code_challenge_methods_supported'] ?? ['S256']), true) && ! empty($doc['authorization_endpoint'])];
        $allowed = ($value['allowedTenants'] ?? []) ?: [$tenantGuid];
        $checks[] = ['name' => 'Política de tenant inclui o tenant da organização', 'ok' => $tenantGuid !== null && in_array($tenantGuid, $allowed, true)];
        $checks[] = ['name' => 'Redirect URIs com https (http apenas localhost)', 'ok' => collect($value['redirectUris'])->every(fn ($u) => EntraAuthSettings::isSafeUri((string) $u, false))];

        $ok = collect($checks)->every(fn ($c) => $c['ok']);

        return [
            'ok' => $ok,
            'step' => 'discovery',
            'code' => $ok ? 'discovery_ok' : 'discovery_mismatch',
            'message' => $ok ? 'Descoberta OIDC válida. Falta o teste de login/token.' : 'A descoberta OIDC não confere com a configuração.',
            'checks' => $checks,
            'issuer' => $issuer,
            'tenantGuid' => $tenantGuid,
            'jwksUri' => $jwksOk ? $jwksUri : null,
            'durationMs' => $ms,
            'complete' => false,
            'detail' => $ok ? 'Descoberta OK; execute o teste de login/token.' : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $value
     * @param  array<string,mixed>|null  $discovery  resultado da descoberta (tenantGuid/jwksUri)
     * @return array<string,mixed>
     */
    public function testToken(array $value, string $accessToken, ?array $discovery): array
    {
        if (! $discovery || empty($discovery['tenantGuid'])) {
            return ['ok' => false, 'step' => 'token', 'code' => 'discovery_required', 'message' => 'Execute a descoberta OIDC antes do teste de token.'];
        }
        $cfg = EntraAuthConfig::fromSetting($value, $discovery);
        try {
            $claims = $this->tokens->validate($accessToken, $cfg);
        } catch (Throwable $e) {
            $reason = match (true) {
                str_contains($e->getMessage(), 'Expired') || str_contains($e->getMessage(), 'expired') => 'token_expired',
                str_contains($e->getMessage(), 'Audience') => 'audience_mismatch',
                str_contains($e->getMessage(), 'Issuer') => 'issuer_mismatch',
                str_contains($e->getMessage(), 'Tenant') => 'tenant_not_allowed',
                str_contains($e->getMessage(), 'Scope') => 'scope_missing',
                default => 'token_invalid',
            };

            return ['ok' => false, 'step' => 'token', 'code' => $reason, 'message' => 'O access token foi recusado: '.str_replace('_', ' ', $reason).'.', 'tenantGuid' => $discovery['tenantGuid'], 'jwksUri' => $discovery['jwksUri'] ?? null];
        }

        return [
            'ok' => true,
            'step' => 'token',
            'code' => 'token_ok',
            'message' => 'Login e access token validados com a configuração candidata.',
            'complete' => true,
            'detail' => 'Validado; ative para passar a usar esta configuração.',
            'tenantGuid' => $discovery['tenantGuid'],
            'jwksUri' => $discovery['jwksUri'] ?? null,
            'checks' => $discovery['checks'] ?? [],
            // Somente metadados não sensíveis do token (nunca o token).
            'claimsSummary' => ['audience' => is_array($claims['aud'] ?? null) ? implode(',', $claims['aud']) : ($claims['aud'] ?? null), 'scopes' => $claims['scp'] ?? null, 'version' => $claims['ver'] ?? null, 'appRoles' => count((array) ($claims['roles'] ?? []))],
        ];
    }
}
