<?php

namespace App\Storage\Azure;

use App\Support\Correlation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Cliente mínimo do Blob REST (Put/Get/Head/Delete Blob e Get Container Properties) sobre o cliente HTTP do
 * Laravel, assinado com Shared Key. Sem redirecionamentos, com timeouts e retry em 500/503. Os anexos têm no máximo
 * 20 MB, então Put Blob é um único PUT e as respostas são lidas inteiras. Erros viram AzureBlobException com código estável.
 */
final class AzureBlobClient
{
    private readonly SharedKeySigner $signer;

    /**
     * @param  string  $baseUrl  https://{conta}.blob.core.windows.net ou o endpoint do emulador (com /{conta})
     */
    public function __construct(
        private readonly string $account,
        string $keyBase64,
        private readonly string $baseUrl,
        private readonly string $container,
        private readonly string $apiVersion = '2023-11-03',
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 5,
    ) {
        $this->signer = new SharedKeySigner($account, $keyBase64);
    }

    public function put(string $blob, string $contents, ?string $contentType = null, bool $onlyIfNew = false): void
    {
        // Content-Type entra na assinatura: o valor assinado é exatamente o enviado
        $headers = ['x-ms-blob-type' => 'BlockBlob', 'Content-Type' => $contentType ?: 'application/octet-stream'];
        if ($onlyIfNew) {
            $headers['If-None-Match'] = '*';
        }
        $this->send('PUT', $this->blobPath($blob), [], $headers, $contents);
    }

    public function get(string $blob): string
    {
        return $this->send('GET', $this->blobPath($blob))->body();
    }

    /** @return array{size:int, contentType:?string, lastModified:?int}|null null quando o blob não existe */
    public function head(string $blob): ?array
    {
        try {
            $r = $this->send('HEAD', $this->blobPath($blob));
        } catch (AzureBlobException $e) {
            if ($e->errorCode === 'not_found') {
                return null;
            }
            throw $e;
        }

        return ['size' => (int) $r->header('Content-Length'), 'contentType' => $r->header('Content-Type') ?: null, 'lastModified' => ($m = $r->header('Last-Modified')) ? (strtotime($m) ?: null) : null];
    }

    public function delete(string $blob): void
    {
        try {
            $this->send('DELETE', $this->blobPath($blob));
        } catch (AzureBlobException $e) {
            if ($e->errorCode !== 'not_found') {
                throw $e;
            }
        }
    }

    /** Somente para o emulador (em Azure real o contêiner nasce no portal). */
    public function createContainerIfMissing(): void
    {
        try {
            $this->send('PUT', '/'.rawurlencode($this->container), ['restype' => 'container']);
        } catch (AzureBlobException $e) {
            if ($e->errorCode !== 'conflict') {
                throw $e;
            }
        }
    }

    /** @return array{publicAccess:?string, date:?string} */
    public function containerProperties(): array
    {
        $r = $this->send('GET', '/'.rawurlencode($this->container), ['restype' => 'container']);

        return ['publicAccess' => $r->header('x-ms-blob-public-access') ?: null, 'date' => $r->header('Date') ?: null];
    }

    private function blobPath(string $blob): string
    {
        return '/'.rawurlencode($this->container).'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($blob, '/'))));
    }

    /**
     * @param  array<string,string>  $query
     * @param  array<string,string>  $extra
     */
    private function send(string $method, string $path, array $query = [], array $extra = [], ?string $body = null): Response
    {
        $url = rtrim($this->baseUrl, '/').$path.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $signedPath = (string) parse_url($url, PHP_URL_PATH);
        // O cliente do Laravel envia Content-Type: application/json por padrão (e withHeaders duplicaria a chave).
        // Por isso o tipo é definido uma única vez, via contentType(), e é exatamente o valor assinado.
        $contentType = $extra['Content-Type'] ?? 'application/octet-stream';
        $length = $body === null ? ($method === 'PUT' ? '0' : '') : (string) strlen($body);
        for ($attempt = 1; ; $attempt++) {
            $sent = array_diff_key($extra, ['Content-Type' => 1, 'Content-Length' => 1]) + ['x-ms-date' => gmdate('D, d M Y H:i:s').' GMT', 'x-ms-version' => $this->apiVersion, 'x-ms-client-request-id' => Correlation::id()];
            $signed = $sent + ['Content-Type' => $contentType, 'Content-Length' => $length];
            $sent['Authorization'] = $this->signer->authorization($method, $signedPath, $query, $signed);
            try {
                $request = Http::contentType($contentType)->withHeaders($sent)->timeout($this->timeout)->connectTimeout($this->connectTimeout)->withOptions(['allow_redirects' => false]);
                if ($body !== null) {
                    $request = $request->withBody($body, $contentType);
                }
                $response = $request->send($method, $url);
            } catch (ConnectionException $e) {
                if ($attempt < 3) {
                    Sleep::sleep(2 ** $attempt);

                    continue;
                }
                throw new AzureBlobException(str_contains(strtolower($e->getMessage()), 'timed out') ? 'timeout' : 'connection_failed', 'Não foi possível conectar ao Azure Blob Storage.', null, true);
            }
            if ($response->successful()) {
                return $response;
            }
            if (in_array($response->status(), [500, 503], true) && $attempt < 3) {
                Sleep::sleep(2 ** $attempt);

                continue;
            }
            throw $this->error($response);
        }
    }

    private function error(Response $response): AzureBlobException
    {
        $status = $response->status();
        $code = $response->header('x-ms-error-code');
        if (! $code && preg_match('~<Code>([A-Za-z0-9]+)</Code>~', $response->body(), $m)) {
            $code = $m[1];
        }
        $map = [
            'KeyBasedAuthenticationNotPermitted' => ['shared_key_disabled', 'A conta de armazenamento não permite autenticação por chave (Shared Key).'],
            'ContainerNotFound' => ['container_not_found', 'O contêiner não existe na conta de armazenamento.'],
            'BlobNotFound' => ['not_found', 'Arquivo não encontrado no armazenamento.'],
            'ResourceNotFound' => ['not_found', 'Recurso não encontrado no armazenamento.'],
            'AuthorizationFailure' => ['storage_forbidden', 'A credencial não tem permissão para esta operação.'],
            'AuthorizationPermissionMismatch' => ['storage_forbidden', 'A credencial não tem permissão para esta operação.'],
            'ConditionNotMet' => ['conflict', 'O arquivo já existe no armazenamento.'],
            'BlobAlreadyExists' => ['conflict', 'O arquivo já existe no armazenamento.'],
        ];
        // Azure responde AuthenticationFailed; o emulador responde AuthorizationFailure com "failed to authenticate".
        // AuthorizationFailure sem essa mensagem é firewall/permissão (storage_forbidden).
        if ($code === 'AuthenticationFailed' || ($code === 'AuthorizationFailure' && stripos($response->body(), 'failed to authenticate') !== false)) {
            $skew = stripos($response->body(), 'date header') !== false || stripos($response->body(), 'too old') !== false;

            return new AzureBlobException($skew ? 'clock_skew' : 'credential_invalid', $skew ? 'O relógio do servidor está defasado em relação ao Azure.' : 'Conta ou chave recusadas pelo Azure (assinatura inválida).', $status);
        }
        if ($code && isset($map[$code])) {
            return new AzureBlobException($map[$code][0], $map[$code][1], $status);
        }

        return match (true) {
            $status === 403 => new AzureBlobException('storage_forbidden', 'Acesso negado pelo armazenamento.', 403),
            $status === 404 => new AzureBlobException('not_found', 'Recurso não encontrado no armazenamento.', 404),
            $status === 409, $status === 412 => new AzureBlobException('conflict', 'O arquivo já existe no armazenamento.', $status),
            $status >= 500 => new AzureBlobException('provider_error', "O armazenamento respondeu HTTP $status.", $status, true),
            default => new AzureBlobException('provider_error', "O armazenamento respondeu HTTP $status.", $status),
        };
    }
}
