<?php

namespace App\Integrations\Rest;

use App\Integrations\IntegrationUnavailable;
use App\Models\IntegrationBinding;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationExecution;
use App\Support\Correlation;
use App\Support\Masker;
use Throwable;

/**
 * Uso dos endpoints do catálogo pelo BPM:
 *  - consulta exposta nos formulários ("api:<identificador>");
 *  - operação do BPM vinculada a um endpoint (de/para de entrada e saída);
 *  - escrita idempotente: a mesma chave nunca repete um POST concluído; resultado incerto (tempo
 *    esgotado após o envio) exige reconciliação manual antes de nova tentativa;
 *  - teste pelo administrador.
 * Cada chamada vira um integration_execution com pedido/resposta mascarados (sem dados pessoais).
 */
class RestIntegration
{
    public function __construct(private readonly RestCaller $caller) {}

    /** @return array<string,mixed>|null campos expostos (caminho => valor) ou null = não encontrado */
    public function lookup(IntegrationEndpoint $endpoint, string $value, ?string $instanceId = null): ?array
    {
        $cfg = (array) ($endpoint->lookup ?? []);
        $param = (string) ($cfg['inputParam'] ?? '');
        $fields = (array) ($cfg['fields'] ?? []);
        $item = $this->fetchItem($endpoint, [$param => $value], 'lookup:'.$endpoint->key, $instanceId, fn ($item) => $this->project($item, array_combine($fields, $fields) ?: []));

        return $item;
    }

    /** @return array<string,mixed>|null */
    public function lookupBound(IntegrationBinding $binding, string $value, ?string $instanceId = null): ?array
    {
        $endpoint = $this->usable($binding);
        [$inputs] = $this->mapInputs($binding, ['value' => $value]);

        return $this->fetchItem($endpoint, $inputs, $binding->operation, $instanceId, fn ($item) => $this->project($item, (array) ($binding->output_map ?? [])));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function executeBound(IntegrationBinding $binding, array $payload, string $idempotencyKey, ?string $instanceId = null): array
    {
        $endpoint = $this->usable($binding);
        $attempts = IntegrationExecution::where('idempotency_key', $idempotencyKey)->where('endpoint_key', $endpoint->key);
        $done = (clone $attempts)->where('result', 'success')->orderByDesc('created_at')->first();
        if ($done) {
            return (array) ($done->result_data ?? []); // já executado (ou reconciliado como feito): não repete a escrita
        }
        if ((clone $attempts)->where('result', 'unknown')->exists()) {
            throw new IntegrationUnavailable('Uma tentativa anterior teve resultado incerto (tempo esgotado após o envio): confira no sistema de destino e reconcilie antes de repetir.', 'reconciliation_required');
        }
        [$inputs, $body] = $this->mapInputs($binding, $payload);
        $started = microtime(true);
        try {
            $res = $this->caller->call($endpoint, $inputs, $body);
            $data = is_array($res['json']) ? JsonPaths::get($res['json'], (string) $endpoint->result_path) : $res['json'];
            $outputs = $this->project(is_array($data) && array_is_list($data) ? ($data[0] ?? []) : $data, (array) ($binding->output_map ?? []));
            $this->record($endpoint, $binding->operation, $inputs, $body, ['data' => $outputs], 'success', $res['durationMs'], $res['status'], $idempotencyKey, $instanceId, $outputs);

            return $outputs;
        } catch (IntegrationUnavailable $e) {
            // tempo esgotado numa escrita: o sistema de destino pode ter gravado → resultado incerto
            $result = in_array($e->reason, ['timeout'], true) && $endpoint->isWrite() ? 'unknown' : $e->reason;
            $this->record($endpoint, $binding->operation, $inputs, $body, ['error' => $e->getMessage()], $result, self::ms($started), null, $idempotencyKey, $instanceId);
            throw $e;
        }
    }

    /**
     * Teste pelo administrador (Testar endpoint): devolve a resposta mascarada e os campos inferidos.
     *
     * @param  array<string,mixed>  $inputs
     * @return array<string,mixed>
     */
    public function test(IntegrationEndpoint $endpoint, array $inputs, mixed $body): array
    {
        $started = microtime(true);
        try {
            $res = $this->caller->call($endpoint, $inputs, $body, true);
            $data = is_array($res['json']) ? JsonPaths::get($res['json'], (string) $endpoint->result_path) : $res['json'];
            $inferred = $data !== null ? JsonPaths::infer($data) : ['isList' => false, 'fields' => []];
            $sample = JsonPaths::maskSample($data);
            $this->record($endpoint, 'test:'.$endpoint->key, $inputs, $body, ['shape' => $sample], $res['status'] === 404 ? 'not_found' : 'success', $res['durationMs'], $res['status'], null, null);

            return ['ok' => true, 'status' => $res['status'], 'durationMs' => $res['durationMs'], 'contentType' => $res['contentType'],
                'sample' => $sample, 'inferred' => $inferred, 'text' => $res['text'] !== null ? '(resposta não JSON, '.mb_strlen($res['text']).' car.)' : null];
        } catch (IntegrationUnavailable $e) {
            $this->record($endpoint, 'test:'.$endpoint->key, $inputs, $body, ['error' => $e->getMessage()], $e->reason, self::ms($started), null, null, null);

            return ['ok' => false, 'code' => $e->reason, 'message' => $e->getMessage(), 'durationMs' => self::ms($started)];
        }
    }

    /**
     * Endpoint do vínculo, se utilizável. Vínculo ativo com endpoint/conexão desligado falha
     * (nunca cai silenciosamente para dados simulados num processo real).
     */
    private function usable(IntegrationBinding $binding): IntegrationEndpoint
    {
        $endpoint = $binding->endpoint;
        if (! $endpoint) {
            throw new IntegrationUnavailable("Vínculo {$binding->operation} sem endpoint.", 'not_configured');
        }

        return $endpoint;
    }

    /**
     * @param  array<string,mixed>  $inputs
     * @param  callable(mixed):array<string,mixed>  $project
     * @return array<string,mixed>|null
     */
    private function fetchItem(IntegrationEndpoint $endpoint, array $inputs, string $operation, ?string $instanceId, callable $project): ?array
    {
        $started = microtime(true);
        try {
            $res = $this->caller->call($endpoint, $inputs);
            $data = is_array($res['json']) ? JsonPaths::get($res['json'], (string) $endpoint->result_path) : null;
            if (is_array($data) && array_is_list($data)) {
                $data = $data[0] ?? null;
            }
            if ($res['status'] === 404 || $data === null || $data === []) {
                $this->record($endpoint, $operation, $inputs, null, null, 'not_found', $res['durationMs'], $res['status'], null, $instanceId);

                return null;
            }
            $item = $project($data);
            $this->record($endpoint, $operation, $inputs, null, ['data' => $item], 'success', $res['durationMs'], $res['status'], null, $instanceId);

            return $item;
        } catch (IntegrationUnavailable $e) {
            $this->record($endpoint, $operation, $inputs, null, ['error' => $e->getMessage()], $e->reason, self::ms($started), null, null, $instanceId);
            throw $e;
        } catch (Throwable $e) {
            $this->record($endpoint, $operation, $inputs, null, ['error' => Masker::error($e)], 'error', self::ms($started), null, null, $instanceId);
            throw new IntegrationUnavailable('Falha inesperada na integração.', 'error');
        }
    }

    /**
     * De/para da entrada: chave = destino ("param" | "body:caminho"), valor = "input:<campo>" | "const:<valor>".
     *
     * @param  array<string,mixed>  $payload
     * @return array{0: array<string,mixed>, 1: array<int|string,mixed>|null}
     */
    private function mapInputs(IntegrationBinding $binding, array $payload): array
    {
        $inputs = [];
        $body = null;
        foreach ((array) ($binding->input_map ?? []) as $target => $source) {
            $source = (string) $source;
            $value = str_starts_with($source, 'const:') ? substr($source, 6) : ($payload[substr($source, 6)] ?? null);
            if (str_starts_with((string) $target, 'body:')) {
                $body ??= [];
                JsonPaths::set($body, substr((string) $target, 5), $value);
            } else {
                $inputs[(string) $target] = $value;
            }
        }

        return [$inputs, $body];
    }

    /**
     * @param  array<string,string>  $map  campo de saída => caminho na resposta
     * @return array<string,mixed>
     */
    private function project(mixed $item, array $map): array
    {
        $out = [];
        foreach ($map as $field => $path) {
            $v = JsonPaths::get($item, (string) $path);
            $out[(string) $field] = is_array($v) ? null : $v;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $inputs
     * @param  array<string,mixed>|null  $response
     * @param  array<string,mixed>|null  $resultData
     */
    private function record(IntegrationEndpoint $endpoint, string $operation, array $inputs, mixed $body, ?array $response, string $result, int $ms, ?int $status, ?string $key, ?string $instanceId, ?array $resultData = null): void
    {
        $sensitive = array_values(array_map(fn ($f) => (string) ($f['path'] ?? ''), array_filter((array) ($endpoint->response_fields ?? []), fn ($f) => ! empty($f['sensitive']))));
        $leafs = array_map(fn ($p) => (string) preg_replace('/^.*[.\]]/', '', $p), $sensitive);
        try {
            IntegrationExecution::create([
                'system' => mb_substr($endpoint->connection->system, 0, 40), 'operation' => mb_substr($operation, 0, 120),
                'transport' => mb_substr('REST:'.$endpoint->connection->key, 0, 60), 'endpoint_key' => $endpoint->key, 'idempotency_key' => $key,
                'request_masked' => ['method' => $endpoint->method, 'path' => $endpoint->path, 'inputs' => JsonPaths::maskSensitive($inputs, $leafs), 'body' => $body === null ? null : JsonPaths::maskSensitive($body, $leafs)],
                'response_masked' => $response === null ? null : JsonPaths::maskSensitive($response, $leafs),
                'result' => mb_substr($result, 0, 40), 'attempt' => $key ? IntegrationExecution::where('idempotency_key', $key)->where('endpoint_key', $endpoint->key)->count() + 1 : 1,
                'duration_ms' => $ms, 'http_status' => $status, 'correlation_id' => Correlation::id(), 'process_instance_id' => $instanceId,
                'result_data' => $resultData, 'created_at' => now('UTC'),
            ]);
        } catch (Throwable) {
            // o registro nunca interfere no resultado da integração
        }
    }

    private static function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
