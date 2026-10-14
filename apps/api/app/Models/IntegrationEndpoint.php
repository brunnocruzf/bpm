<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Endpoint ("fonte de dados REST", no vocabulário do SoftExpert): método + caminho com {parâmetros},
 * parâmetros de entrada (PATH/QUERY/HEADER; valor de entrada ou constante NÃO secreta), campos do
 * corpo (do OpenAPI), campos de saída (do OpenAPI ou inferidos de um exemplo mascarado) e exposição
 * opcional como consulta nos formulários.
 *
 * @property string $id
 * @property string $connection_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string|null $tag
 * @property string $method
 * @property string $path
 * @property string|null $operation_id
 * @property bool $enabled
 * @property list<array<string,mixed>>|null $params
 * @property list<array<string,mixed>>|null $body_fields
 * @property list<array<string,mixed>>|null $response_fields
 * @property mixed $response_sample
 * @property bool $response_is_list
 * @property string|null $result_path
 * @property array<string,mixed>|null $lookup
 * @property int|null $timeout_seconds
 * @property string $source
 * @property string|null $contract_hash
 * @property string $contract_status
 * @property list<string>|null $warnings
 * @property int $version
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IntegrationConnection $connection
 */
class IntegrationEndpoint extends Model
{
    use HasUuids;

    public const KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_.\-]{2,79}$/';

    public const PARAM_LOCATIONS = ['path', 'query', 'header'];

    public const TYPES = ['string', 'integer', 'number', 'boolean', 'date', 'object', 'array'];

    protected $table = 'integration_endpoint';

    protected $attributes = ['enabled' => false, 'response_is_list' => false, 'contract_status' => 'ok', 'source' => 'manual', 'version' => 0];

    protected $fillable = ['connection_id', 'key', 'name', 'description', 'tag', 'method', 'path', 'operation_id', 'enabled', 'params', 'body_fields',
        'response_fields', 'response_sample', 'response_is_list', 'result_path', 'lookup', 'timeout_seconds', 'source', 'contract_hash', 'contract_status',
        'warnings', 'version', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'response_is_list' => 'boolean', 'params' => 'array', 'body_fields' => 'array', 'response_fields' => 'array',
            'response_sample' => 'array', 'lookup' => 'array', 'warnings' => 'array', 'timeout_seconds' => 'integer', 'version' => 'integer'];
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    public function isWrite(): bool
    {
        return $this->method !== 'GET';
    }
}
