<?php

namespace App\Integrations\Rest;

use App\Integrations\IntegrationUnavailable;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEndpoint;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Importação assistida de OpenAPI 3.x (Swagger/Swashbuckle) para o catálogo de endpoints:
 * prévia (novo | alterado | inalterado | removido, com alertas) → o administrador escolhe o que
 * importar → endpoints criados DESABILITADOS (escritas também exigem liberação na conexão).
 * Reimportar detecta mudança de contrato por hash (método, caminho, parâmetros, corpo e resposta),
 * preservando nome, constantes, campos de saída inferidos, consulta nos formulários e vínculos.
 */
final class OpenApiImporter
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** @return array{doc: array<string,mixed>, hash: string, bytes: int} */
    public function fetch(string $url): array
    {
        TargetPolicy::assertAllowed($url);
        try {
            $res = Http::accept('application/json')->timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false])->get($url);
        } catch (ConnectionException) {
            throw new IntegrationUnavailable('Não foi possível baixar o documento OpenAPI (rede/egress).', 'connection_failed');
        }
        if (! $res->successful()) {
            throw new IntegrationUnavailable('O documento OpenAPI respondeu HTTP '.$res->status().'.', 'openapi_http_'.$res->status());
        }

        return $this->parse($res->body());
    }

    /** @return array{doc: array<string,mixed>, hash: string, bytes: int} */
    public function parse(string $raw): array
    {
        $bytes = strlen($raw);
        if ($bytes > (int) config('bpm.integrations.max_openapi_bytes', 5 * 1024 * 1024)) {
            throw new IntegrationUnavailable('Documento OpenAPI acima do limite de tamanho.', 'openapi_too_large');
        }
        $doc = json_decode($raw, true, 128);
        if (! is_array($doc)) {
            throw new IntegrationUnavailable('O arquivo não é um JSON válido (use o swagger.json, não a página do Swagger UI).', 'openapi_invalid');
        }
        if (isset($doc['swagger'])) {
            throw new IntegrationUnavailable('Swagger 2.0 não é suportado: exporte em OpenAPI 3 (ex.: Swashbuckle "SerializeAsV2 = false").', 'openapi_unsupported');
        }
        if (! str_starts_with((string) ($doc['openapi'] ?? ''), '3.') || ! is_array($doc['paths'] ?? null)) {
            throw new IntegrationUnavailable('Documento sem "openapi: 3.x" e "paths".', 'openapi_invalid');
        }

        return ['doc' => $doc, 'hash' => hash('sha256', $raw), 'bytes' => $bytes];
    }

    /**
     * @param  array<string,mixed>  $doc
     * @return array{meta: array<string,mixed>, operations: list<array<string,mixed>>, removed: list<array<string,mixed>>, warnings: list<string>}
     */
    public function preview(array $doc, IntegrationConnection $connection): array
    {
        $existing = IntegrationEndpoint::where('connection_id', $connection->id)->get()->keyBy(fn (IntegrationEndpoint $e) => $e->method.' '.$e->path);
        $usedKeys = IntegrationEndpoint::where('connection_id', '!=', $connection->id)->pluck('key')->map(fn ($k) => strtolower((string) $k))->all();
        $ops = [];
        $seen = [];
        foreach ($this->operations($doc) as $op) {
            $id = $op['method'].' '.$op['path'];
            $current = $existing->get($id);
            if ($current) {
                $op['suggestedKey'] = $current->key;
                $op['status'] = $current->contract_hash === $op['contractHash'] ? 'unchanged' : 'changed';
                $op['endpointId'] = $current->id;
                $op['enabled'] = $current->enabled;
            } else {
                $op['suggestedKey'] = $this->uniqueKey($op['suggestedKey'], [...$usedKeys, ...$seen, ...$existing->pluck('key')->map(fn ($k) => strtolower((string) $k))->all()]);
                $op['status'] = 'new';
                $op['endpointId'] = null;
                $op['enabled'] = false;
            }
            $seen[] = strtolower($op['suggestedKey']);
            $ops[] = $op;
        }
        $ids = array_map(fn ($o) => $o['method'].' '.$o['path'], $ops);
        $removed = $existing->filter(fn (IntegrationEndpoint $e) => $e->source === 'swagger' && ! in_array($e->method.' '.$e->path, $ids, true))
            ->map(fn (IntegrationEndpoint $e) => ['endpointId' => $e->id, 'key' => $e->key, 'method' => $e->method, 'path' => $e->path, 'enabled' => $e->enabled])->values()->all();

        $warnings = [];
        $noResponse = count(array_filter($ops, fn ($o) => $o['responseFields'] === []));
        if ($noResponse) {
            $warnings[] = "$noResponse de ".count($ops).' operação(ões) não documentam a resposta: os campos de saída precisam vir de um exemplo colado ou de um teste em homologação (ou peça ao time da API para anotar os tipos de retorno).';
        }
        $insecure = array_filter($ops, fn ($o) => in_array('credential_in_query', $o['flags'], true));
        if ($insecure) {
            $warnings[] = count($insecure).' operação(ões) recebem senha/token na URL (query string) — inseguro; não use para autenticação: '.implode(', ', array_map(fn ($o) => $o['method'].' '.$o['path'], array_slice($insecure, 0, 5))).'.';
        }
        $schemes = (array) ($doc['components']['securitySchemes'] ?? []);

        return [
            'meta' => [
                'title' => (string) ($doc['info']['title'] ?? ''), 'version' => (string) ($doc['info']['version'] ?? ''), 'openapi' => (string) $doc['openapi'],
                'operations' => count($ops), 'reads' => count(array_filter($ops, fn ($o) => $o['method'] === 'GET')), 'writes' => count(array_filter($ops, fn ($o) => $o['method'] !== 'GET')),
                'security' => array_map(fn ($s, $k) => ['name' => $k, 'type' => $s['type'] ?? null, 'in' => $s['in'] ?? null, 'header' => $s['name'] ?? null, 'scheme' => $s['scheme'] ?? null], $schemes, array_keys($schemes)),
                'servers' => array_map(fn ($s) => (string) ($s['url'] ?? ''), (array) ($doc['servers'] ?? [])),
                'tags' => array_values(array_unique(array_map(fn ($o) => $o['tag'], $ops))),
            ],
            'operations' => $ops,
            'removed' => $removed,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string,mixed>  $doc
     * @param  list<string>  $selected  "MÉTODO /caminho"
     * @param  array<string,string>  $keys  "MÉTODO /caminho" => identificador escolhido
     * @return array{created: int, updated: int, unchanged: int, removed: int}
     */
    public function apply(array $doc, string $hash, IntegrationConnection $connection, array $selected, array $keys, User $by): array
    {
        $preview = $this->preview($doc, $connection);
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0];
        DB::transaction(function () use ($preview, $hash, $doc, $connection, $selected, $keys, $by, &$result) {
            foreach ($preview['operations'] as $op) {
                $id = $op['method'].' '.$op['path'];
                if (! in_array($id, $selected, true)) {
                    continue;
                }
                if ($op['status'] === 'unchanged') {
                    $result['unchanged']++;

                    continue;
                }
                $endpoint = $op['endpointId'] ? IntegrationEndpoint::find($op['endpointId']) : new IntegrationEndpoint(['connection_id' => $connection->id, 'enabled' => false, 'source' => 'swagger', 'version' => 0]);
                if (! $endpoint) {
                    continue;
                }
                $isNew = ! $endpoint->exists;
                $key = $isNew ? (string) ($keys[$id] ?? $op['suggestedKey']) : $endpoint->key;
                if ($isNew && (! preg_match(IntegrationEndpoint::KEY_PATTERN, $key) || IntegrationEndpoint::where('key', $key)->exists())) {
                    $key = $this->uniqueKey($op['suggestedKey'], IntegrationEndpoint::pluck('key')->map(fn ($k) => strtolower((string) $k))->all());
                }
                // preserva modo/constante já configurados dos parâmetros com o mesmo nome
                $oldParams = collect((array) ($endpoint->params ?? []))->keyBy('name');
                $params = array_map(fn ($p) => [...$p, 'mode' => $oldParams[$p['name']]['mode'] ?? 'input', 'constant' => $oldParams[$p['name']]['constant'] ?? null], $op['params']);
                $before = $endpoint->exists ? $endpoint->only(['method', 'path', 'params', 'body_fields', 'response_fields']) : null;
                $endpoint->fill([
                    'key' => $key, 'name' => $endpoint->exists ? $endpoint->name : mb_substr($op['name'], 0, 200), 'description' => $op['description'],
                    'tag' => $op['tag'], 'method' => $op['method'], 'path' => $op['path'], 'operation_id' => $op['operationId'], 'params' => $params,
                    'body_fields' => $op['bodyFields'],
                    // a documentação sem resposta não apaga campos já inferidos de um exemplo
                    'response_fields' => $op['responseFields'] ?: ($endpoint->response_fields ?? []),
                    'response_is_list' => $op['responseFields'] ? $op['responseIsList'] : $endpoint->response_is_list,
                    'contract_hash' => $op['contractHash'], 'contract_status' => 'ok', 'warnings' => $op['warnings'], 'updated_by' => $by->id,
                    'version' => $endpoint->version + 1,
                ]);
                $endpoint->save();
                $result[$isNew ? 'created' : 'updated']++;
                if (! $isNew) {
                    AuditLogger::record('integration.endpoint_contract_updated', 'integration_endpoint', $endpoint->id, ['key' => $endpoint->key, 'fields' => array_keys(array_filter($before ?? [], fn ($v, $k) => $v != $endpoint->{$k}, ARRAY_FILTER_USE_BOTH))], null, $by, context: ['object_name' => $endpoint->name]);
                }
            }
            foreach ($preview['removed'] as $r) {
                IntegrationEndpoint::whereKey($r['endpointId'])->update(['contract_status' => 'removed']);
                $result['removed']++;
            }
            $connection->forceFill([
                'openapi_hash' => $hash, 'openapi_title' => mb_substr((string) ($doc['info']['title'] ?? ''), 0, 200), 'openapi_version' => mb_substr((string) ($doc['info']['version'] ?? ''), 0, 60),
                'openapi_imported_at' => now(), 'openapi_checked_at' => now(), 'updated_by' => $by->id,
            ])->save();
            AuditLogger::record('integration.openapi_imported', 'integration_connection', $connection->id, [...$result, 'title' => $doc['info']['title'] ?? null, 'version' => $doc['info']['version'] ?? null], null, $by, context: ['object_name' => $connection->name]);
        });

        return $result;
    }

    /**
     * Verificação periódica (bpm:openapi-check): marca endpoints com contrato alterado/removido, sem aplicar nada.
     *
     * @param  array<string,mixed>  $doc
     * @return array{changed: int, removed: int}
     */
    public function markDrift(array $doc, IntegrationConnection $connection): array
    {
        $preview = $this->preview($doc, $connection);
        $changed = 0;
        foreach ($preview['operations'] as $op) {
            if ($op['status'] === 'changed' && $op['endpointId']) {
                $changed += IntegrationEndpoint::whereKey($op['endpointId'])->where('contract_status', '!=', 'changed')->update(['contract_status' => 'changed']);
            }
        }
        $removed = 0;
        foreach ($preview['removed'] as $r) {
            $removed += IntegrationEndpoint::whereKey($r['endpointId'])->where('contract_status', '!=', 'removed')->update(['contract_status' => 'removed']);
        }
        $connection->forceFill(['openapi_checked_at' => now()])->save();

        return ['changed' => $changed, 'removed' => $removed];
    }

    /**
     * @param  array<string,mixed>  $doc
     * @return list<array<string,mixed>>
     */
    private function operations(array $doc): array
    {
        $out = [];
        foreach ((array) $doc['paths'] as $path => $item) {
            if (! is_array($item) || ! is_string($path) || ! str_starts_with($path, '/')) {
                continue;
            }
            $shared = (array) ($item['parameters'] ?? []);
            foreach (self::METHODS as $m) {
                $o = $item[$m] ?? null;
                if (! is_array($o)) {
                    continue;
                }
                $method = strtoupper($m);
                $flags = [];
                $warnings = [];
                $params = [];
                foreach ([...$shared, ...(array) ($o['parameters'] ?? [])] as $p) {
                    $p = $this->deref($doc, $p);
                    $in = (string) ($p['in'] ?? '');
                    $name = (string) ($p['name'] ?? '');
                    if ($name === '' || ! in_array($in, IntegrationEndpoint::PARAM_LOCATIONS, true) || ($in === 'header' && strcasecmp($name, 'Authorization') === 0)) {
                        continue;
                    }
                    if ($in === 'query' && preg_match('/pass|senha|secret|token|apikey|api_key/i', $name)) {
                        $flags[] = 'credential_in_query';
                    }
                    $schema = $this->deref($doc, $p['schema'] ?? []);
                    $params[$in.':'.$name] = ['name' => mb_substr($name, 0, 80), 'in' => $in, 'type' => self::type($schema), 'required' => $in === 'path' || ! empty($p['required']), 'description' => mb_substr((string) ($p['description'] ?? ''), 0, 300) ?: null];
                }
                $body = [];
                $bodySchema = null;
                foreach ((array) ($o['requestBody']['content'] ?? []) as $ct => $c) {
                    if (str_contains((string) $ct, 'json')) {
                        $bodySchema = $c['schema'] ?? null;
                        break;
                    }
                }
                if ($bodySchema !== null) {
                    $this->flattenSchema($doc, $bodySchema, '', $body, 0);
                }
                $response = [];
                $isList = false;
                foreach (['200', '201', 'default'] as $code) {
                    foreach ((array) ($o['responses'][$code]['content'] ?? []) as $ct => $c) {
                        if (str_contains((string) $ct, 'json') && isset($c['schema'])) {
                            $schema = $this->deref($doc, $c['schema']);
                            $isList = ($schema['type'] ?? null) === 'array';
                            $this->flattenSchema($doc, $isList ? ($schema['items'] ?? []) : $schema, '', $response, 0);
                            break 2;
                        }
                    }
                }
                if (in_array('credential_in_query', $flags, true)) {
                    $warnings[] = 'Recebe senha/token na URL (query string): inseguro, não use para autenticação.';
                }
                if ($method !== 'GET') {
                    $warnings[] = 'Escrita no sistema externo: fica desabilitada até liberação da conexão (aprovação) e não é repetida automaticamente sem reconciliação.';
                }
                if ($response === []) {
                    $warnings[] = 'A documentação não descreve a resposta: cole um exemplo (Parâmetros de saída) ou teste em homologação.';
                }
                if (! empty($o['deprecated'])) {
                    $warnings[] = 'Operação marcada como obsoleta (deprecated).';
                }
                $tag = mb_substr((string) (($o['tags'] ?? [])[0] ?? 'Geral'), 0, 80);
                $summary = trim((string) ($o['summary'] ?? ''));
                $operationId = trim((string) ($o['operationId'] ?? '')) ?: null;
                $params = array_values($params);
                $contract = ['m' => $method, 'p' => $path, 'params' => array_map(fn ($p) => [$p['name'], $p['in'], $p['type'], $p['required']], $params), 'body' => $body, 'resp' => $response, 'list' => $isList];
                $out[] = [
                    'method' => $method, 'path' => mb_substr($path, 0, 300), 'tag' => $tag, 'name' => $summary !== '' ? $summary : "$method $path",
                    'description' => mb_substr(trim((string) ($o['description'] ?? '')), 0, 2000) ?: null, 'operationId' => $operationId ? mb_substr($operationId, 0, 120) : null,
                    'suggestedKey' => $this->suggestKey($operationId, $tag, $path, $method), 'params' => $params, 'bodyFields' => $body, 'responseFields' => $response,
                    'responseIsList' => $isList, 'flags' => array_values(array_unique($flags)), 'warnings' => $warnings,
                    'contractHash' => hash('sha256', (string) json_encode($contract)),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $doc
     * @param  list<array<string,mixed>>  $out
     */
    private function flattenSchema(array $doc, mixed $schema, string $prefix, array &$out, int $depth, array $seen = []): void
    {
        if (! is_array($schema) || $depth > 6 || count($out) >= 300) {
            return;
        }
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            if (in_array($ref, $seen, true)) {
                return; // referência circular
            }
            $seen[] = $ref;
        }
        $schema = $this->deref($doc, $schema);
        foreach (['allOf'] as $combo) {
            foreach ((array) ($schema[$combo] ?? []) as $part) {
                $this->flattenSchema($doc, $part, $prefix, $out, $depth + 1, $seen);
            }
        }
        $required = (array) ($schema['required'] ?? []);
        foreach ((array) ($schema['properties'] ?? []) as $name => $prop) {
            $prop = is_array($prop) ? $prop : [];
            $resolved = $this->deref($doc, $prop);
            $path = $prefix === '' ? (string) $name : "$prefix.$name";
            $type = self::type($resolved);
            if ($type === 'object' && isset($resolved['properties'])) {
                $this->flattenSchema($doc, $prop, $path, $out, $depth + 1, $seen);
            } elseif ($type === 'array' && is_array($resolved['items'] ?? null) && in_array(self::type($this->deref($doc, $resolved['items'])), ['object'], true)) {
                $this->flattenSchema($doc, $resolved['items'], $path.'[]', $out, $depth + 1, $seen);
            } else {
                $out[] = ['path' => mb_substr($path, 0, 200), 'type' => $type, 'required' => in_array($name, $required, true), 'label' => mb_substr((string) $name, 0, 120),
                    'sensitive' => JsonPaths::isSensitive($path), 'description' => mb_substr((string) ($resolved['description'] ?? ''), 0, 300) ?: null];
            }
        }
    }

    /**
     * @param  array<string,mixed>  $doc
     * @return array<string,mixed>
     */
    private function deref(array $doc, mixed $node): array
    {
        $node = is_array($node) ? $node : [];
        for ($i = 0; $i < 5 && isset($node['$ref']) && is_string($node['$ref']); $i++) {
            if (! str_starts_with($node['$ref'], '#/')) {
                return []; // referências externas não são seguidas
            }
            $target = $doc;
            foreach (explode('/', substr($node['$ref'], 2)) as $seg) {
                $seg = str_replace(['~1', '~0'], ['/', '~'], $seg);
                $target = is_array($target) ? ($target[$seg] ?? null) : null;
            }
            $node = is_array($target) ? $target : [];
        }

        return $node;
    }

    /** @param array<string,mixed> $schema */
    private static function type(array $schema): string
    {
        $type = (string) ($schema['type'] ?? (isset($schema['properties']) ? 'object' : 'string'));
        if ($type === 'string' && in_array($schema['format'] ?? null, ['date', 'date-time'], true)) {
            return 'date';
        }

        return in_array($type, IntegrationEndpoint::TYPES, true) ? $type : 'string';
    }

    private function suggestKey(?string $operationId, string $tag, string $path, string $method): string
    {
        if ($operationId && preg_match(IntegrationEndpoint::KEY_PATTERN, $operationId) && ! str_contains($operationId, ' ')) {
            return $operationId;
        }
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== '' && ! str_starts_with($s, '{') && ! preg_match('/^v\d+$/i', $s)));
        $base = Str::studly($tag).'.'.Str::studly((string) (end($segments) ?: strtolower($method)));
        $base = (string) preg_replace('/[^A-Za-z0-9_.\-]/', '', $base);

        return preg_match(IntegrationEndpoint::KEY_PATTERN, $base) ? $base : 'Endpoint.'.substr(md5($method.$path), 0, 8);
    }

    /** @param list<string> $used em minúsculas */
    private function uniqueKey(string $key, array $used): string
    {
        $candidate = $key;
        for ($i = 2; in_array(strtolower($candidate), $used, true); $i++) {
            $candidate = mb_substr($key, 0, 75).'-'.$i;
        }

        return $candidate;
    }
}
