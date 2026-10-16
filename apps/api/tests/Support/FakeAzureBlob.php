<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Emulação do Blob REST para testes (Http::fake). CONFERE a assinatura Shared Key com uma canonicalização escrita
 * de forma independente (direto da especificação), mantém os blobs em memória e injeta falhas. Hosts de outros
 * serviços recebem null para que os fakes se componham.
 */
class FakeAzureBlob
{
    /** chave de teste (base64) */
    public const KEY = 'dGVzdGUtZGEtY2hhdmUtZGEtY29udGEtYXp1cmUtMDEyMzQ1Njc4OWFiY2RlZg==';

    public const ACCOUNT = 'bpmtesteconta';

    /** @var array<string,string> "contêiner/blob" => conteúdo */
    public array $blobs = [];

    /** @var array<string,true> */
    public array $containers = ['anexos' => true];

    public ?string $publicAccess = null;

    /** @var list<array{status:int, code?:string, body?:string}> falhas consumidas em ordem, uma por requisição */
    public array $failures = [];

    /** @var list<string> */
    public array $requests = [];

    public int $skewSeconds = 0;

    public bool $corruptReads = false;

    public function __construct(public string $key = self::KEY, public string $account = self::ACCOUNT) {}

    public static function install(): self
    {
        $fake = new self;
        Http::fake(fn (Request $r) => $fake->handle($r));

        return $fake;
    }

    public function handle(Request $request)
    {
        $host = (string) parse_url($request->url(), PHP_URL_HOST);
        if (! str_ends_with($host, '.blob.core.windows.net') && $host !== 'azurite.test') {
            return null;
        }
        $this->requests[] = $request->method().' '.urldecode((string) parse_url($request->url(), PHP_URL_PATH));
        if ($this->failures) {
            $f = array_shift($this->failures);

            return Http::response($f['body'] ?? '', $f['status'], ['x-ms-error-code' => $f['code'] ?? '']);
        }
        if (! $this->validSignature($request)) {
            return Http::response('<Error><Code>AuthenticationFailed</Code></Error>', 403, ['x-ms-error-code' => 'AuthenticationFailed']);
        }
        $path = ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');
        if ($host === 'azurite.test') {
            $path = preg_replace('#^[^/]+/#', '', $path) ?? $path; // remove /{conta}
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $parts = explode('/', $path, 2);
        $container = rawurldecode($parts[0]);
        $blob = isset($parts[1]) ? rawurldecode($parts[1]) : null;
        if (! isset($this->containers[$container])) {
            return Http::response('<Error><Code>ContainerNotFound</Code></Error>', 404, ['x-ms-error-code' => 'ContainerNotFound']);
        }
        if ($blob === null) {
            $date = gmdate('D, d M Y H:i:s', time() + $this->skewSeconds).' GMT';
            if ($request->method() === 'PUT') {
                return Http::response('', 201);
            }

            return Http::response('', 200, array_filter(['x-ms-blob-public-access' => $this->publicAccess, 'Date' => $date]));
        }
        $key = "$container/$blob";

        return match ($request->method()) {
            'PUT' => $this->put($request, $key),
            'GET' => isset($this->blobs[$key]) ? Http::response($this->corruptReads ? strrev($this->blobs[$key]) : $this->blobs[$key], 200) : $this->notFound(),
            'HEAD' => isset($this->blobs[$key]) ? Http::response('', 200, ['Content-Length' => (string) strlen($this->blobs[$key]), 'Content-Type' => 'application/octet-stream']) : Http::response('', 404, ['x-ms-error-code' => 'BlobNotFound']),
            'DELETE' => isset($this->blobs[$key]) ? $this->deleted($key) : $this->notFound(),
            default => Http::response('', 405),
        };
    }

    private function put(Request $request, string $key)
    {
        if ($request->hasHeader('If-None-Match') && isset($this->blobs[$key])) {
            return Http::response('<Error><Code>BlobAlreadyExists</Code></Error>', 409, ['x-ms-error-code' => 'BlobAlreadyExists']);
        }
        $this->blobs[$key] = $request->body();

        return Http::response('', 201);
    }

    private function deleted(string $key)
    {
        unset($this->blobs[$key]);

        return Http::response('', 202);
    }

    private function notFound()
    {
        return Http::response('<Error><Code>BlobNotFound</Code></Error>', 404, ['x-ms-error-code' => 'BlobNotFound']);
    }

    /** Canonicalização independente: VERB, 11 cabeçalhos padrão, x-ms-* ordenados e recurso com query ordenada. */
    private function validSignature(Request $r): bool
    {
        $h = [];
        foreach ($r->headers() as $name => $values) {
            $h[strtolower($name)] = trim((string) $values[0]);
        }
        $date = strtotime($h['x-ms-date'] ?? '');
        if (! $date || abs($date - time()) > 900) {
            return false;
        }
        $length = $r->method() === 'PUT' ? (string) strlen($r->body()) : '';
        $length = $length === '0' ? '' : $length;
        $std = [$h['content-encoding'] ?? '', $h['content-language'] ?? '', $length, $h['content-md5'] ?? '', $h['content-type'] ?? '', '', $h['if-modified-since'] ?? '', $h['if-match'] ?? '', $h['if-none-match'] ?? '', $h['if-unmodified-since'] ?? '', $h['range'] ?? ''];
        $ms = array_filter($h, fn ($k) => str_starts_with($k, 'x-ms-'), ARRAY_FILTER_USE_KEY);
        ksort($ms);
        $canonicalHeaders = '';
        foreach ($ms as $k => $v) {
            $canonicalHeaders .= "$k:$v\n";
        }
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        ksort($q);
        $resource = '/'.$this->account.$path;
        foreach ($q as $k => $v) {
            $resource .= "\n".strtolower($k).':'.$v;
        }
        $toSign = $r->method()."\n".implode("\n", $std)."\n".$canonicalHeaders.$resource;
        $expected = 'SharedKey '.$this->account.':'.base64_encode(hash_hmac('sha256', $toSign, (string) base64_decode($this->key, true), true));

        return hash_equals($expected, (string) ($r->header('Authorization')[0] ?? ''));
    }
}
