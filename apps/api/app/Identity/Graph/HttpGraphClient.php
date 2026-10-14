<?php

namespace App\Identity\Graph;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Cliente HTTP do Graph com resiliência: 429/503/504 respeitando Retry-After (com teto),
 * backoff exponencial, timeouts e limite de tentativas. Só aceita URLs do próprio Graph
 * (nextLink/deltaLink) — evita seguir links arbitrários com o token de aplicação.
 */
class HttpGraphClient implements GraphClient
{
    /** @param Closure():string $tokenProvider */
    public function __construct(private readonly Closure $tokenProvider) {}

    public function get(string $pathOrUrl): array
    {
        $base = rtrim((string) config('bpm.directory.graph_base_url'), '/');
        $url = str_starts_with($pathOrUrl, 'https://') ? $pathOrUrl : $base.'/'.ltrim($pathOrUrl, '/');
        if (! str_starts_with($url, 'https://graph.microsoft.com/')) {
            throw new GraphException('graph_error', 'Link de paginação fora do Microsoft Graph recusado.');
        }
        $maxAttempts = max(1, (int) config('bpm.directory.max_attempts', 5));
        $maxWait = (int) config('bpm.directory.max_retry_wait_seconds', 120);

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::withToken(($this->tokenProvider)())
                    ->acceptJson()
                    ->timeout((int) config('bpm.directory.timeout', 30))
                    ->connectTimeout(5)
                    ->get($url);
            } catch (ConnectionException) {
                if ($attempt >= $maxAttempts) {
                    throw new GraphException('timeout', 'Tempo esgotado ao consultar o Microsoft Graph.', true);
                }
                Sleep::sleep(min($maxWait, 2 ** $attempt));

                continue;
            }

            if ($response->successful()) {
                return (array) $response->json();
            }
            $status = $response->status();
            if (in_array($status, [429, 503, 504], true) && $attempt < $maxAttempts) {
                $retryAfter = (int) ($response->header('Retry-After') ?: 0);
                $wait = $retryAfter > 0 ? $retryAfter : 2 ** $attempt;
                if ($wait > $maxWait) {
                    throw new GraphException('throttled', "Graph pediu espera de {$wait}s (acima do limite); a rodada será retomada depois.", true, $status);
                }
                Sleep::sleep($wait);

                continue;
            }
            throw $this->error($response);
        }
    }

    private function error(Response $response): GraphException
    {
        $status = $response->status();
        $code = (string) $response->json('error.code');
        $map = [
            'Authorization_RequestDenied' => ['consent_missing', 'Permissão de aplicação ausente ou sem consentimento do administrador (ex.: User.Read.All, Group.Read.All).'],
            'Authorization_IdentityNotFound' => ['consent_missing', 'Aplicativo sem identidade/consentimento no tenant.'],
            'InvalidAuthenticationToken' => ['credential_invalid', 'Token de aplicação recusado pelo Graph.'],
            'syncStateNotFound' => ['delta_expired', 'Cursor delta expirado: é necessária recarga completa.'],
            'resyncRequired' => ['delta_expired', 'Graph pediu ressincronização completa.'],
            'Request_ResourceNotFound' => ['not_found', 'Objeto não encontrado no diretório.'],
        ];
        if ($status === 410) {
            return new GraphException('delta_expired', 'Graph pediu ressincronização completa (410).', false, 410, $response->header('Location') ?: null);
        }
        if (isset($map[$code])) {
            return new GraphException($map[$code][0], $map[$code][1], false, $status);
        }
        if ($status === 403) {
            return new GraphException('consent_missing', 'Acesso negado pelo Graph (permissão/consentimento).', false, 403);
        }
        if ($status === 404) {
            return new GraphException('not_found', 'Objeto não encontrado no diretório.', false, 404);
        }
        if ($status === 429) {
            return new GraphException('throttled', 'Graph limitou as requisições (429).', true, 429);
        }

        return new GraphException('graph_error', "Graph respondeu HTTP $status".($code ? " ($code)" : '').'.', $status >= 500, $status);
    }
}
