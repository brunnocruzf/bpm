<?php

namespace App\Integrations\Ax;

use App\Integrations\BpmOperations;
use App\Integrations\IntegrationUnavailable;
use App\Integrations\Rest\RestIntegration;
use App\Models\IntegrationBinding;
use App\Models\IntegrationExecution;
use App\Support\Correlation;
use App\Support\Masker;
use Throwable;

/**
 * Adaptador único do AX com seleção de transporte por operação
 * (MOCK | REST_FACADE | AIF_WCF | SQL_READONLY_VIEW). Toda chamada é registrada
 * em integration_execution com dados mascarados.
 *
 * Vínculo ativo no catálogo de integrações (Administração → Integrações → Mapeamentos) tem
 * prioridade: a operação passa a chamar o endpoint REST vinculado (ex.: API do AX — apidax),
 * com de/para de entrada e saída. Sem vínculo, vale o transporte configurado (MOCK por padrão).
 */
class AxGateway
{
    public const WRITE_OPERATIONS = ['transferAndBlock'];

    /** Vínculo ativo da operação com um endpoint do catálogo (ou null). */
    public function binding(string $operation): ?IntegrationBinding
    {
        return IntegrationBinding::with('endpoint.connection')->where('operation', $operation)->where('enabled', true)->first();
    }

    /** Nome do transporte efetivo (exibido nas consultas e no painel). Mapeamento personalizado sem vínculo ativo: INATIVO. */
    public function transportName(string $operation): string
    {
        $binding = $this->binding($operation);
        if ($binding?->endpoint) {
            return 'REST:'.$binding->endpoint->connection->key;
        }

        return BpmOperations::isFixed($operation) ? $this->transportFor($operation)->name() : 'INATIVO';
    }

    /** Transporte configurado de uma operação fixa (as chaves têm ponto: não dá para usar config("...$operation")). */
    public static function configuredTransport(string $operation): string
    {
        return (string) (((array) config('bpm.ax.transports'))[$operation] ?? 'MOCK');
    }

    public function transportFor(string $operation): AxTransport
    {
        if (! BpmOperations::isFixed($operation)) {
            // mapeamento personalizado: não há dados simulados; inativo, falha (nunca inventa resultado)
            throw new IntegrationUnavailable("O mapeamento \"$operation\" está inativo: ative-o em Integrações → Mapeamentos.", 'not_configured');
        }
        $name = self::configuredTransport($operation);

        return match ($name) {
            'MOCK' => app(MockAxTransport::class),
            'REST_FACADE', 'AIF_WCF', 'SQL_READONLY_VIEW' => new UnconfiguredAxTransport($name),
            default => throw new IntegrationUnavailable("Transporte desconhecido para $operation.", 'not_configured'),
        };
    }

    /** @return array<string,mixed>|null */
    public function lookup(string $operation, string $value, ?string $processInstanceId = null): ?array
    {
        if ($binding = $this->binding($operation)) {
            return app(RestIntegration::class)->lookupBound($binding, $value, $processInstanceId);
        }
        $transport = $this->transportFor($operation);
        $started = hrtime(true);
        try {
            $result = $transport->lookup($operation, $value);
            $this->record($operation, $transport->name(), ['value' => $value], $result, $result === null ? 'not_found' : 'success', $started, null, $processInstanceId);

            return $result;
        } catch (Throwable $e) {
            $reason = $e instanceof IntegrationUnavailable ? $e->reason : 'error';
            $this->record($operation, $transport->name(), ['value' => $value], ['error' => Masker::error($e)], $reason, $started, null, $processInstanceId);
            throw $e instanceof IntegrationUnavailable ? $e : new IntegrationUnavailable('Falha na consulta ao AX.', 'error');
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function execute(string $operation, array $payload, string $idempotencyKey, ?string $processInstanceId = null): array
    {
        if ($binding = $this->binding($operation)) {
            return app(RestIntegration::class)->executeBound($binding, $payload, $idempotencyKey, $processInstanceId);
        }
        $transport = $this->transportFor($operation);
        if (in_array($operation, self::WRITE_OPERATIONS, true) && $transport->name() === 'SQL_READONLY_VIEW') {
            // Escritas no AX nunca usam SQL direto (seção 7.2).
            throw new IntegrationUnavailable('Escrita no AX via SQL é proibida.', 'not_configured');
        }
        $started = hrtime(true);
        try {
            $result = $transport->execute($operation, $payload, $idempotencyKey);
            $this->record($operation, $transport->name(), $payload, $result, 'success', $started, $idempotencyKey, $processInstanceId);

            return $result;
        } catch (Throwable $e) {
            $reason = $e instanceof IntegrationUnavailable ? $e->reason : 'error';
            $this->record($operation, $transport->name(), $payload, ['error' => Masker::error($e)], $reason, $started, $idempotencyKey, $processInstanceId);
            throw $e instanceof IntegrationUnavailable ? $e : new IntegrationUnavailable('Falha na operação no AX.', 'error');
        }
    }

    private function record(string $operation, string $transport, array $request, ?array $response, string $result, int $started, ?string $key, ?string $instanceId): void
    {
        $attempt = $key ? IntegrationExecution::where('idempotency_key', $key)->count() + 1 : 1;
        IntegrationExecution::create([
            'system' => 'AX2012R3',
            'operation' => $operation,
            'transport' => $transport,
            'idempotency_key' => $key,
            'request_masked' => Masker::mask($request),
            'response_masked' => $response === null ? null : Masker::mask($response),
            'result' => $result,
            'attempt' => $attempt,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'correlation_id' => Correlation::id(),
            'process_instance_id' => $instanceId,
            'created_at' => now('UTC'),
        ]);
    }
}
