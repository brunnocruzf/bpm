<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Registro de cada chamada a sistema externo (pedido e resposta mascarados). result_data guarda
 * somente as saídas mapeadas de escritas, para a idempotência (nunca repetir um POST já concluído).
 *
 * @property string $id
 * @property string $system
 * @property string $operation
 * @property string $transport
 * @property string|null $idempotency_key
 * @property array<string,mixed>|null $request_masked
 * @property array<string,mixed>|null $response_masked
 * @property string $result
 * @property int $attempt
 * @property int|null $duration_ms
 * @property string|null $correlation_id
 * @property string|null $process_instance_id
 * @property string|null $endpoint_key
 * @property int|null $http_status
 * @property array<string,mixed>|null $result_data
 * @property Carbon|null $created_at
 */
class IntegrationExecution extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'integration_execution';

    protected $fillable = ['system', 'operation', 'transport', 'idempotency_key', 'request_masked', 'response_masked', 'result', 'attempt', 'duration_ms',
        'correlation_id', 'process_instance_id', 'created_at', 'endpoint_key', 'http_status', 'result_data'];

    protected function casts(): array
    {
        return ['request_masked' => 'array', 'response_masked' => 'array', 'result_data' => 'array', 'created_at' => 'datetime'];
    }
}
