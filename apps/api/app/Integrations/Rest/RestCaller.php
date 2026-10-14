<?php

namespace App\Integrations\Rest;

use App\Integrations\IntegrationUnavailable;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEndpoint;
use App\Settings\SecretStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Executa um endpoint do catálogo SOMENTE no servidor: monta URL ({parâmetros} de caminho,
 * query, cabeçalhos não secretos), aplica a autenticação da conexão (credencial do cofre, nunca
 * constante do endpoint), valida o destino (TargetPolicy), sem seguir redirecionamentos, com tempo
 * limite e limite de tamanho da resposta. Erros viram códigos estáveis (IntegrationErrors).
 */
class RestCaller
{
    private const AUTH_HEADERS = ['authorization', 'cookie', 'x-api-key', 'api-key', 'apikey', 'proxy-authorization'];

    public function __construct(private readonly SecretStore $secrets) {}

    /**
     * @param  array<string,mixed>  $inputs  valores dos parâmetros (nome => valor)
     * @return array{status:int, json:mixed, text:?string, durationMs:int, url:string, contentType:?string}
     */
    public function call(IntegrationEndpoint $endpoint, array $inputs, mixed $body = null, bool $test = false): array
    {
        $c = $endpoint->connection;
        if (! $test && (! $c->enabled || ! $endpoint->enabled)) {
            throw new IntegrationUnavailable("Endpoint {$endpoint->key} ou conexão {$c->key} desabilitado.", 'not_configured');
        }
        if ($endpoint->isWrite() && ! $c->allow_writes) {
            throw new IntegrationUnavailable("Escrita não liberada na conexão {$c->key} (exige aprovação).", 'write_not_allowed');
        }
        [$url, $query, $headers] = $this->build($endpoint, $inputs);
        TargetPolicy::assertAllowed($url);
        $timeout = $endpoint->timeout_seconds ?: $c->timeout_seconds ?: (int) config('bpm.integrations.default_timeout', 15);
        $started = microtime(true);
        $response = $this->send($c, $endpoint->method, $url, $query, $headers, $body, $timeout);
        if ($response->status() === 401 && $c->auth_type === 'login') {
            // token expirado: renova uma vez
            $this->forgetToken($c);
            $response = $this->send($c, $endpoint->method, $url, $query, $headers, $body, $timeout);
        }
        $ms = (int) round((microtime(true) - $started) * 1000);

        return $this->interpret($response, $ms, $url.($query ? '?'.http_build_query(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $query)) : ''));
    }

    /**
     * Teste da conexão: política de rede → obtenção do token (login/OAuth2) ou presença da credencial →
     * chamada REAL de verificação (GET no "caminho de verificação", se configurado). Sem caminho de
     * verificação, credenciais fixas ficam como "não verificadas" (nunca um sucesso presumido).
     *
     * @return array{ok:bool, verified:bool, code:string, message:string, durationMs:int, checks:list<array<string,mixed>>}
     */
    public function testConnection(IntegrationConnection $c): array
    {
        $started = microtime(true);
        $checks = [];
        $stage = 'Destino permitido pela política de rede';
        $cfg = (array) ($c->auth_config ?? []);
        $verified = false;
        try {
            TargetPolicy::assertAllowed($c->base_url);
            $checks[] = ['name' => $stage, 'ok' => true, 'detail' => parse_url($c->base_url, PHP_URL_HOST)];
            if (in_array($c->auth_type, ['login', 'oauth2_client_credentials'], true)) {
                $stage = $c->auth_type === 'login' ? 'Login na API e obtenção do token' : 'Token OAuth2 (client credentials)';
                $this->forgetToken($c);
                $this->token($c);
                $checks[] = ['name' => $stage, 'ok' => true, 'detail' => 'token obtido (guardado só em cache criptografado)'];
                $verified = true;
            } elseif ($c->auth_type !== 'none') {
                $stage = 'Credencial configurada no cofre';
                if ($this->secret($c) === null) {
                    throw new IntegrationUnavailable('Credencial não configurada (ou segredo montado ilegível).', 'credential_missing');
                }
                $checks[] = ['name' => $stage, 'ok' => true, 'detail' => self::AUTH_DESCRIPTION[$c->auth_type] ?? $c->auth_type];
            }
            $testPath = trim((string) ($cfg['testPath'] ?? ''));
            if ($testPath !== '') {
                $stage = 'Chamada de verificação (GET '.$testPath.')';
                $url = rtrim($c->base_url, '/').'/'.ltrim($testPath, '/');
                TargetPolicy::assertAllowed($url);
                $t = microtime(true);
                $res = $this->send($c, 'GET', $url, [], [], null, $c->timeout_seconds ?: 15);
                $status = $res->status();
                if (in_array($status, [401, 403], true)) {
                    throw new IntegrationUnavailable("A API recusou a credencial (HTTP $status) na chamada de verificação.", $status === 401 ? 'credential_invalid' : 'forbidden');
                }
                if ($status >= 300) {
                    throw new IntegrationUnavailable("A chamada de verificação respondeu HTTP $status.", $status >= 500 ? 'provider_error' : 'request_rejected');
                }
                $checks[] = ['name' => $stage, 'ok' => true, 'detail' => "HTTP $status · ".(int) round((microtime(true) - $t) * 1000).' ms'];
                $verified = true;
            }
            $message = $verified
                ? 'Conexão e credencial verificadas na API.'
                : ($c->auth_type === 'none'
                    ? 'Destino permitido. Informe um caminho de verificação para testar uma chamada real.'
                    : 'Credencial definida, mas NÃO verificada: informe um caminho de verificação (GET leve, ex.: /v1/Financeiro/Moeda) para testar de verdade.');

            return ['ok' => true, 'verified' => $verified, 'code' => $verified ? 'connection_ok' : 'not_verified', 'message' => $message,
                'durationMs' => (int) round((microtime(true) - $started) * 1000), 'checks' => $checks];
        } catch (IntegrationUnavailable $e) {
            $checks[] = ['name' => $stage, 'ok' => false, 'code' => $e->reason, 'message' => $e->getMessage()];

            return ['ok' => false, 'verified' => false, 'code' => $e->reason, 'message' => $e->getMessage(), 'durationMs' => (int) round((microtime(true) - $started) * 1000), 'checks' => $checks];
        }
    }

    /**
     * @param  array<string,mixed>  $inputs
     * @return array{0:string, 1:array<string,mixed>, 2:array<string,string>}
     */
    public function build(IntegrationEndpoint $endpoint, array $inputs): array
    {
        $path = $endpoint->path;
        $query = [];
        $headers = [];
        foreach ((array) ($endpoint->params ?? []) as $p) {
            $name = (string) $p['name'];
            $value = ($p['mode'] ?? 'input') === 'constant' ? ($p['constant'] ?? null) : ($inputs[$name] ?? null);
            if ($value === null || $value === '') {
                if (! empty($p['required'])) {
                    throw new IntegrationUnavailable("Parâmetro obrigatório \"$name\" não informado.", 'request_invalid');
                }

                continue;
            }
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            match ($p['in']) {
                'path' => $path = str_replace('{'.$name.'}', rawurlencode($value), $path),
                'query' => $query[$name] = $value,
                'header' => in_array(strtolower($name), self::AUTH_HEADERS, true)
                    ? throw new IntegrationUnavailable('Credencial não pode ser parâmetro do endpoint: use a autenticação da conexão.', 'request_invalid')
                    : $headers[$name] = $value,
                default => null,
            };
        }
        if (preg_match('/\{[^}]+\}/', $path)) {
            throw new IntegrationUnavailable('Parâmetro de caminho sem valor: '.$path.'.', 'request_invalid');
        }

        return [rtrim($endpoint->connection->base_url, '/').'/'.ltrim($path, '/'), $query, $headers];
    }

    public function forgetToken(IntegrationConnection $c): void
    {
        Cache::forget($this->tokenKey($c));
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,string>  $headers
     */
    private function send(IntegrationConnection $c, string $method, string $url, array $query, array $headers, mixed $body, int $timeout): Response
    {
        try {
            $req = Http::withHeaders([...$headers, ...$this->authHeaders($c)])->accept('application/json')
                ->timeout($timeout)->connectTimeout(min(5, $timeout))->withOptions(['allow_redirects' => false]);
            $options = $query ? ['query' => $query] : [];
            if ($method !== 'GET' && $body !== null) {
                $options['json'] = $body;
            }

            return $req->send($method, $url, $options);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout');
            throw new IntegrationUnavailable($timedOut ? 'Tempo esgotado ao chamar a API.' : 'Não foi possível conectar à API (rede/egress/DNS).', $timedOut ? 'timeout' : 'connection_failed');
        }
    }

    /** @return array{status:int, json:mixed, text:?string, durationMs:int, url:string, contentType:?string} */
    private function interpret(Response $r, int $ms, string $url): array
    {
        $status = $r->status();
        $raw = $r->body();
        if (strlen($raw) > (int) config('bpm.integrations.max_response_bytes', 2 * 1024 * 1024)) {
            throw new IntegrationUnavailable('Resposta acima do limite de tamanho configurado.', 'response_too_large');
        }
        $contentType = $r->header('Content-Type') ?: null;
        $json = null;
        $trim = ltrim($raw);
        if ($trim !== '' && (str_contains((string) $contentType, 'json') || str_starts_with($trim, '{') || str_starts_with($trim, '['))) {
            $json = json_decode($raw, true, 128);
        }
        if ($status >= 300 && $status !== 404) {
            [$code, $msg] = match (true) {
                $status < 400 => ['redirect_blocked', 'A API respondeu com redirecionamento (não seguido).'],
                $status === 400 || $status === 422 => ['request_rejected', 'A API recusou os dados enviados (HTTP '.$status.').'],
                $status === 401 => ['credential_invalid', 'Credencial recusada pela API (401).'],
                $status === 403 => ['forbidden', 'Acesso negado pela API (403).'],
                $status === 408 || $status === 504 => ['timeout', 'A API não respondeu a tempo (HTTP '.$status.').'],
                $status === 429 => ['throttled', 'A API limitou as requisições (429).'],
                $status >= 500 => ['provider_error', 'Erro interno na API (HTTP '.$status.').'],
                default => ['request_rejected', 'A API recusou a requisição (HTTP '.$status.').'],
            };
            throw new IntegrationUnavailable($msg, $code);
        }

        return ['status' => $status, 'json' => $json, 'text' => $json === null && $trim !== '' ? mb_substr($raw, 0, 500) : null, 'durationMs' => $ms, 'url' => $url, 'contentType' => $contentType];
    }

    /**
     * Cabeçalhos de autenticação (sempre em cabeçalho, nunca na URL).
     *
     * @return array<string,string>
     */
    public function authHeaders(IntegrationConnection $c): array
    {
        $cfg = (array) ($c->auth_config ?? []);

        return match ($c->auth_type) {
            // Authorization: <prefixo> <token>  (prefixo padrão "Bearer"; aceita o valor já com o prefixo)
            'bearer_token' => ['Authorization' => self::withPrefix($this->required($c, 'Token'), (string) ($cfg['prefix'] ?? 'Bearer'))],
            // Authorization: Basic base64(usuário:senha)
            'basic' => ['Authorization' => 'Basic '.base64_encode((string) ($cfg['userName'] ?? '').':'.$this->required($c, 'Senha'))],
            // <cabeçalho>: [prefixo ]<chave>
            'api_key_header' => [(string) ($cfg['headerName'] ?? 'X-API-Key') => self::withPrefix($this->required($c, 'Chave da API'), (string) ($cfg['prefix'] ?? ''))],
            'login', 'oauth2_client_credentials' => ['Authorization' => 'Bearer '.$this->token($c)],
            default => [],
        };
    }

    public const AUTH_DESCRIPTION = [
        'bearer_token' => 'Authorization: Bearer <token>',
        'basic' => 'Authorization: Basic <usuário:senha em base64>',
        'api_key_header' => '<cabeçalho>: <chave>',
        'login' => 'login do usuário de serviço → Authorization: Bearer <token>',
        'oauth2_client_credentials' => 'OAuth2 client credentials → Authorization: Bearer <token>',
    ];

    private function required(IntegrationConnection $c, string $what): string
    {
        return $this->secret($c) ?? throw new IntegrationUnavailable("$what não configurado(a) na conexão.", 'credential_missing');
    }

    private static function withPrefix(string $value, string $prefix): string
    {
        $value = trim($value);
        $prefix = trim($prefix);
        if ($prefix === '' || stripos($value, $prefix.' ') === 0) {
            return $value; // sem prefixo, ou o valor já veio com ele (ex.: "Bearer 123|...")
        }

        return $prefix.' '.$value;
    }

    /** Login (ex.: POST /v1/User/login {userName, password}) ou OAuth2 client credentials → token em cache criptografado. */
    private function token(IntegrationConnection $c): string
    {
        $cached = Cache::get($this->tokenKey($c));
        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (Throwable) {
                // cache inválido: faz login de novo
            }
        }
        $cfg = (array) ($c->auth_config ?? []);
        if ($c->auth_type === 'oauth2_client_credentials') {
            return $this->oauthToken($c, $cfg);
        }
        $password = $this->secret($c) ?? throw new IntegrationUnavailable('Senha do usuário de serviço não configurada.', 'credential_missing');
        $loginUrl = rtrim($c->base_url, '/').'/'.ltrim((string) ($cfg['loginPath'] ?? '/v1/User/login'), '/');
        TargetPolicy::assertAllowed($loginUrl);
        try {
            $res = Http::accept('application/json')->timeout($c->timeout_seconds ?: 15)->connectTimeout(5)->withOptions(['allow_redirects' => false])
                ->post($loginUrl, [(string) ($cfg['userField'] ?? 'userName') => (string) ($cfg['userName'] ?? ''), (string) ($cfg['passwordField'] ?? 'password') => $password]);
        } catch (ConnectionException) {
            throw new IntegrationUnavailable('Não foi possível conectar ao login da API.', 'connection_failed');
        }
        if (in_array($res->status(), [400, 401, 403], true)) {
            throw new IntegrationUnavailable('Usuário de serviço ou senha recusados pelo login da API.', 'credential_invalid');
        }
        if (! $res->successful()) {
            throw new IntegrationUnavailable('O login da API respondeu HTTP '.$res->status().'.', 'provider_error');
        }
        $json = json_decode($res->body(), true);
        $token = null;
        foreach (array_filter([(string) ($cfg['tokenPath'] ?? ''), 'token', 'accessToken', 'access_token', 'jwt', 'data.token', 'result.token']) as $path) {
            $v = is_array($json) ? JsonPaths::get($json, $path) : null;
            if (is_string($v) && $v !== '') {
                $token = $v;
                break;
            }
        }
        if ($token === null && is_string($json) && $json !== '') {
            $token = $json;
        }
        if ($token === null && preg_match('/^[A-Za-z0-9\-_=.|]{16,}$/', trim($res->body(), "\" \n\r"))) {
            $token = trim($res->body(), "\" \n\r");
        }
        if ($token === null) {
            throw new IntegrationUnavailable('Login sem token na resposta: informe o caminho do token na conexão.', 'token_not_found');
        }
        $ttl = max(1, (int) ($cfg['tokenTtlMinutes'] ?? config('bpm.integrations.token_ttl_minutes', 50)));
        Cache::put($this->tokenKey($c), Crypt::encryptString($token), now()->addMinutes($ttl));

        return $token;
    }

    /**
     * OAuth2 client credentials (RFC 6749 §4.4): POST form no endpoint de token com client_id/secret
     * (no corpo, nunca na URL) → access_token em cache criptografado até expires_in − 60 s.
     *
     * @param  array<string,mixed>  $cfg
     */
    private function oauthToken(IntegrationConnection $c, array $cfg): string
    {
        $tokenUrl = (string) ($cfg['tokenUrl'] ?? '');
        $secret = $this->required($c, 'Client secret');
        TargetPolicy::assertAllowed($tokenUrl);
        try {
            $res = Http::asForm()->accept('application/json')->timeout($c->timeout_seconds ?: 15)->connectTimeout(5)->withOptions(['allow_redirects' => false])
                ->post($tokenUrl, array_filter(['grant_type' => 'client_credentials', 'client_id' => (string) ($cfg['clientId'] ?? ''), 'client_secret' => $secret, 'scope' => $cfg['scope'] ?? null]));
        } catch (ConnectionException) {
            throw new IntegrationUnavailable('Não foi possível conectar ao endpoint de token OAuth2.', 'connection_failed');
        }
        if (in_array($res->status(), [400, 401, 403], true)) {
            throw new IntegrationUnavailable('Client ID/secret ou scope recusados pelo endpoint de token (HTTP '.$res->status().').', 'credential_invalid');
        }
        $token = (string) ($res->json('access_token') ?? '');
        if (! $res->successful() || $token === '') {
            throw new IntegrationUnavailable('O endpoint de token não devolveu access_token (HTTP '.$res->status().').', 'token_not_found');
        }
        $ttl = max(60, (int) ($res->json('expires_in') ?? 3600) - 60);
        Cache::put($this->tokenKey($c), Crypt::encryptString($token), now()->addSeconds($ttl));

        return $token;
    }

    private function secret(IntegrationConnection $c): ?string
    {
        return $c->secret_ref ? $this->secrets->get($c->secret_ref) : null;
    }

    private function tokenKey(IntegrationConnection $c): string
    {
        return 'bpm:integration-token:'.$c->id.':'.substr(sha1((string) $c->secret_ref.'|'.json_encode($c->auth_config).'|'.$c->base_url), 0, 16);
    }
}
