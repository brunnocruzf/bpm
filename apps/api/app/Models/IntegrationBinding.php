<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Vínculo de uma operação do BPM (consulta dos formulários ou tarefa de integração) com um endpoint:
 * de/para da entrada (destino no endpoint ⇐ campo da operação ou constante) e da saída (campo da
 * operação ⇐ caminho na resposta). Sem vínculo ativo, a operação continua no transporte configurado (MOCK).
 *
 * Mapeamentos personalizados (custom = true) definem a própria operação: tipo (consulta nos formulários
 * ou etapa automática), rótulo e os dados de entrada/saída. Não têm transporte simulado: inativos, falham.
 *
 * @property string $id
 * @property string $operation
 * @property string|null $endpoint_id
 * @property bool $enabled
 * @property array<string,string>|null $input_map
 * @property array<string,string>|null $output_map
 * @property string|null $updated_by
 * @property bool $custom
 * @property string|null $kind
 * @property string|null $label
 * @property string|null $description
 * @property list<array{key:string,label:string}>|null $inputs
 * @property list<array{key:string,label:string}>|null $outputs
 * @property Carbon|null $updated_at
 * @property-read IntegrationEndpoint|null $endpoint
 */
class IntegrationBinding extends Model
{
    use HasUuids;

    /** Prefixo da operação (e da fonte de consulta / integração do fluxo) dos mapeamentos personalizados. */
    public const CUSTOM_PREFIX = 'map:';

    /** Identificador digitado pelo administrador (sem o prefixo). */
    public const CUSTOM_KEY = '/^[A-Za-z][A-Za-z0-9_]{2,40}$/';

    /** Chave de dado do BPM (campo do formulário / dado do processo). */
    public const FIELD_KEY = '/^[A-Za-z][A-Za-z0-9_]{0,62}$/';

    public const KINDS = ['lookup', 'task'];

    protected $table = 'integration_binding';

    protected $fillable = ['operation', 'endpoint_id', 'enabled', 'input_map', 'output_map', 'updated_by', 'custom', 'kind', 'label', 'description', 'inputs', 'outputs'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'custom' => 'boolean', 'input_map' => 'array', 'output_map' => 'array', 'inputs' => 'array', 'outputs' => 'array'];
    }

    /** @return BelongsTo<IntegrationEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(IntegrationEndpoint::class, 'endpoint_id');
    }

    /**
     * @param  Builder<IntegrationBinding>  $query
     * @return Builder<IntegrationBinding>
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('custom', true);
    }

    /** Ativo e utilizável: vínculo ligado, endpoint e conexão habilitados. */
    public function usable(): bool
    {
        // getAttribute: dentro de um Model, "->connection" leria a propriedade protegida (nome da conexão do banco)
        $connection = $this->endpoint?->getAttribute('connection');

        return $this->enabled && $this->endpoint && $this->endpoint->enabled && $connection instanceof IntegrationConnection && $connection->enabled;
    }

    /** @return array<string,string> chave => rótulo */
    public function inputsMap(): array
    {
        return self::pairs($this->inputs);
    }

    /** @return array<string,string> chave => rótulo */
    public function outputsMap(): array
    {
        return self::pairs($this->outputs);
    }

    /**
     * JSON gravado no banco: tolera itens incompletos (só usa os que têm chave).
     *
     * @param  array<mixed>|null  $list
     * @return array<string,string>
     */
    private static function pairs(?array $list): array
    {
        $out = [];
        foreach ((array) $list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? '');
            if ($key !== '') {
                $out[$key] = (string) (($row['label'] ?? '') !== '' ? $row['label'] : $key);
            }
        }

        return $out;
    }
}
