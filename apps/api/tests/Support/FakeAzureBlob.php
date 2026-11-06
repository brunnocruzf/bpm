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

    /** @var array<string, array<string,string>> blocos ainda sem commit */
    public array $blocks = [];

    public int $pageSize = 5000;

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
            if (($query['comp'] ?? '') === 'list') {
                return $this->listing($container, (string) ($query['prefix'] ?? ''), (string) ($query['marker'] ?? ''));
            }

            return Http::response('', 200, array_filter(['x-ms-blob-public-access' => $this->publicAccess, 'Date' => $date]));
        }
        $key = "$container/$blob";
        if ($request->method() === 'PUT' && ($query['comp'] ?? '') === 'block') {
            $this->blocks[$key][(string) $query['blockid']] = $request->body();

            return Http::response('', 201);
        }
        if ($request->method() === 'PUT' && ($query['comp'] ?? '') === 'blocklist') {
            return $this->commit($request, $key);
        }

        return match ($request->method()) {
            'PUT' => $this->put($request, $key),
            'GET' => isset($this->blobs[$key]) ? $this->read($request, $key) : $this->notFound(),
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

    private function read(Request $request, string $key)
    {
        $content = $this->corruptReads ? strrev($this->blobs[$key]) : $this->blobs[$key];
        $range = (string) ($request->header('x-ms-range')[0] ?? '');
        if (preg_match('/^bytes=(\d+)-(\d+)$/', $range, $m)) {
            return Http::response(substr($content, (int) $m[1], (int) $m[2] - (int) $m[1] + 1), 206);
        }

        return Http::response($content, 200);
    }

    /** Monta o blob na ordem da lista: bloco ausente é erro, como no Azure. */
    private function commit(Request $request, string $key)
    {
        preg_match_all('~<Latest>([^<]+)</Latest>~', $request->body(), $m);
        $content = '';
        foreach ($m[1] as $id) {
            if (! isset($this->blocks[$key][$id])) {
                return Http::response('<Error><Code>InvalidBlockList</Code></Error>', 400, ['x-ms-error-code' => 'InvalidBlockList']);
            }
            $content .= $this->blocks[$key][$id];
        }
        $this->blobs[$key] = $content;
        unset($this->blocks[$key]);

        return Http::response('', 201);
    }

    private function listing(string $container, string $prefix, string $marker)
    {
        $names = array_values(array_filter(array_keys($this->blobs), fn ($k) => str_starts_with($k, "$container/$prefix")));
        sort($names);
        $offset = (int) $marker;
        $items = '';
        foreach (array_slice($names, $offset, $this->pageSize) as $name) {
            $items .= '<Blob><Name>'.htmlspecialchars(substr($name, strlen($container) + 1)).'</Name><Properties><Content-Length>'.strlen($this->blobs[$name]).'</Content-Length></Properties></Blob>';
        }
        $next = $offset + $this->pageSize < count($names) ? (string) ($offset + $this->pageSize) : '';

        return Http::response("<?xml version=\"1.0\" encoding=\"utf-8\"?><EnumerationResults><Blobs>$items</Blobs><NextMarker>$next</NextMarker></EnumerationResults>", 200);
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
