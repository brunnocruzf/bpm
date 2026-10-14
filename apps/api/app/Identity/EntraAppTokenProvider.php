<?php

namespace App\Identity;

use App\Identity\Graph\GraphException;
use App\Settings\SecretStore;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Token app-only (OAuth 2.0 client credentials) para integrações backend: sincronização do
 * diretório (Graph, somente leitura) e envio de e-mail M365. Nunca roda no browser e não
 * depende da sessão de um administrador. Credenciais suportadas:
 *  - certificate: client assertion JWT assinado com a chave privada (preferido em produção);
 *  - federated:   token de workload identity federation lido de arquivo montado;
 *  - client_secret: somente quando aprovado pela organização e guardado no cofre.
 * Tokens ficam apenas em memória do processo (sem cache compartilhado em disco/banco).
 */
class EntraAppTokenProvider
{
    /** @var array<string, array{token:string, exp:int}> */
    private array $memory = [];

    public function __construct(private readonly SecretStore $secrets) {}

    /**
     * @param  array{tenantId:string, clientId:string, credentialType:string, credentialRef:?string, authorityHost?:string}  $cfg
     */
    public function token(array $cfg, string $scope): string
    {
        $cacheKey = hash('sha256', implode('|', [$cfg['tenantId'], $cfg['clientId'], $cfg['credentialType'], $cfg['credentialRef'] ?? '', $scope]));
        if (isset($this->memory[$cacheKey]) && $this->memory[$cacheKey]['exp'] > time() + 300) {
            return $this->memory[$cacheKey]['token'];
        }
        $host = $cfg['authorityHost'] ?? 'login.microsoftonline.com';
        $endpoint = "https://$host/{$cfg['tenantId']}/oauth2/v2.0/token";
        $form = ['client_id' => $cfg['clientId'], 'scope' => $scope, 'grant_type' => 'client_credentials', ...$this->credential($cfg, $endpoint)];

        try {
            $response = Http::asForm()->timeout(15)->connectTimeout(5)->post($endpoint, $form);
        } catch (ConnectionException) {
            throw new GraphException('identity_unreachable', 'Não foi possível contatar o Entra ID (rede/egress).', true);
        }
        if (! $response->successful()) {
            $error = (string) $response->json('error');
            $description = (string) $response->json('error_description');
            $aadsts = preg_match('/AADSTS(\d+)/', $description, $m) ? 'AADSTS'.$m[1] : null;
            [$code, $message] = match (true) {
                in_array($aadsts, ['AADSTS90002', 'AADSTS900023'], true) => ['tenant_invalid', 'Tenant inválido ou inexistente.'],
                in_array($aadsts, ['AADSTS700016'], true) => ['app_not_found', 'Aplicativo (client ID) não encontrado no tenant.'],
                in_array($aadsts, ['AADSTS7000215', 'AADSTS7000222', 'AADSTS700027', 'AADSTS700023', 'AADSTS700024'], true), $error === 'invalid_client' => ['credential_invalid', 'Credencial do aplicativo inválida ou expirada.'],
                $error === 'unauthorized_client' => ['consent_missing', 'Aplicativo sem permissão/consentimento para este fluxo.'],
                $error === 'invalid_scope' => ['scope_invalid', 'Scope solicitado inválido.'],
                default => ['token_error', 'Falha ao obter token do Entra ID'.($aadsts ? " ($aadsts)" : '').'.'],
            };
            throw new GraphException($code, $message, $response->status() >= 500);
        }
        $token = (string) $response->json('access_token');
        $this->memory[$cacheKey] = ['token' => $token, 'exp' => time() + (int) $response->json('expires_in', 3600)];

        return $token;
    }

    /**
     * Lê as permissões de aplicação (claim "roles") do token emitido para nós mesmos.
     * Não valida assinatura: é usado apenas para diagnosticar consentimento ausente.
     *
     * @return list<string>
     */
    public static function grantedRoles(string $token): array
    {
        $parts = explode('.', $token);
        $payload = isset($parts[1]) ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true) : null;

        return is_array($payload) ? array_values(array_map('strval', (array) ($payload['roles'] ?? []))) : [];
    }

    /**
     * Claims públicas do token app-only para diagnóstico (nunca o token): app, tenant e permissões de aplicação.
     *
     * @return array{appId: ?string, appName: ?string, tenantId: ?string, roles: list<string>}
     */
    public static function summary(string $token): array
    {
        $parts = explode('.', $token);
        $p = isset($parts[1]) ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true) : null;
        $p = is_array($p) ? $p : [];

        return [
            'appId' => isset($p['appid']) ? (string) $p['appid'] : (isset($p['azp']) ? (string) $p['azp'] : null),
            'appName' => isset($p['app_displayname']) ? mb_substr((string) $p['app_displayname'], 0, 120) : null,
            'tenantId' => isset($p['tid']) ? (string) $p['tid'] : null,
            'roles' => array_values(array_map('strval', (array) ($p['roles'] ?? []))),
        ];
    }

    public function forget(): void
    {
        $this->memory = [];
    }

    /**
     * @param  array<string,mixed>  $cfg
     * @return array<string,string>
     */
    private function credential(array $cfg, string $audience): array
    {
        $type = (string) $cfg['credentialType'];
        $material = $this->secrets->get($cfg['credentialRef'] ?? null);
        if ($material === null) {
            throw new GraphException('credential_missing', 'Credencial do aplicativo não configurada ou ilegível.', false);
        }

        return match ($type) {
            'client_secret' => ['client_secret' => $material],
            'federated' => ['client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => $material],
            'certificate' => ['client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => self::certificateAssertion($material, (string) $cfg['clientId'], $audience)],
            default => throw new GraphException('credential_unsupported', 'Tipo de credencial não suportado neste ambiente.', false),
        };
    }

    /** JWT client assertion (RFC 7523) com cabeçalho x5t#S256 do certificado. */
    public static function certificateAssertion(string $pem, string $clientId, string $audience): string
    {
        $info = self::certificateInfo($pem);
        $key = openssl_pkey_get_private($pem);
        if (! $info || ! $key) {
            throw new GraphException('credential_invalid', 'Certificado/chave privada inválidos (PEM com CERTIFICATE e PRIVATE KEY).', false);
        }
        $now = time();
        $claims = ['aud' => $audience, 'iss' => $clientId, 'sub' => $clientId, 'jti' => (string) Str::uuid(), 'nbf' => $now - 30, 'iat' => $now, 'exp' => $now + 600];

        // x5t (SHA-1) mantido por compatibilidade; x5t#S256 é o preferido pela plataforma de identidade.
        return JWT::encode($claims, $key, 'RS256', null, ['x5t#S256' => $info['x5tS256'], 'x5t' => $info['x5t']]);
    }

    /** @return array{x5tS256:string, x5t:string, thumbprintSha1:string, notAfter:int, subject:string}|null */
    public static function certificateInfo(string $pem): ?array
    {
        if (! preg_match('/-----BEGIN CERTIFICATE-----[\s\S]+?-----END CERTIFICATE-----/', $pem, $m)) {
            return null;
        }
        $cert = openssl_x509_read($m[0]);
        if (! $cert) {
            return null;
        }
        $parsed = openssl_x509_parse($cert) ?: [];
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $m[0]) ?? '', true) ?: '';
        $b64url = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return [
            'x5tS256' => $b64url(hash('sha256', $der, true)),
            'x5t' => $b64url(sha1($der, true)),
            'thumbprintSha1' => strtoupper(sha1($der)),
            'notAfter' => (int) ($parsed['validTo_time_t'] ?? 0),
            'subject' => (string) ($parsed['name'] ?? ''),
        ];
    }
}
