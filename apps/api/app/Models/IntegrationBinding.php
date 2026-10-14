<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Vínculo de uma operação do BPM (consulta dos formulários ou tarefa de integração) com um endpoint:
 * de/para da entrada (destino no endpoint ⇐ campo da operação ou constante) e da saída (campo da
 * operação ⇐ caminho na resposta). Sem vínculo ativo, a operação continua no transporte configurado (MOCK).
 *
 * @property string $id
 * @property string $operation
 * @property string|null $endpoint_id
 * @property bool $enabled
 * @property array<string,string>|null $input_map
 * @property array<string,string>|null $output_map
 * @property string|null $updated_by
 * @property Carbon|null $updated_at
 * @property-read IntegrationEndpoint|null $endpoint
 */
class IntegrationBinding extends Model
{
    use HasUuids;

    protected $table = 'integration_binding';

    protected $fillable = ['operation', 'endpoint_id', 'enabled', 'input_map', 'output_map', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'input_map' => 'array', 'output_map' => 'array'];
    }

    /** @return BelongsTo<IntegrationEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(IntegrationEndpoint::class, 'endpoint_id');
    }
}
