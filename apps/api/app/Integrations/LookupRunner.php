<?php

namespace App\Integrations;

use App\Forms\FieldCatalog;
use App\Integrations\Ax\AxGateway;
use App\Integrations\Rest\RestIntegration;
use App\Models\IntegrationEndpoint;

/**
 * Executa a consulta de um campo "axlookup" dos formulários:
 *  - fontes fixas do PO 159 (tpr, item, purchaseOrder, supplier) → AxGateway (vínculo REST ou MOCK);
 *  - fontes "map:<chave>" criadas em Mapeamentos → AxGateway (vínculo REST; inativo falha);
 *  - fontes "api:<identificador>" → endpoint de leitura publicado no catálogo de integrações.
 */
class LookupRunner
{
    public function __construct(private readonly AxGateway $ax, private readonly RestIntegration $rest) {}

    /** @return array<string,mixed>|null */
    public function run(string $source, string $value, ?string $processInstanceId = null): ?array
    {
        if (str_starts_with($source, FieldCatalog::API_PREFIX)) {
            return $this->rest->lookup($this->endpoint($source), $value, $processInstanceId);
        }

        return $this->ax->lookup($this->operation($source), $value, $processInstanceId);
    }

    public function transportName(string $source): string
    {
        if (str_starts_with($source, FieldCatalog::API_PREFIX)) {
            return 'REST:'.$this->endpoint($source)->connection->key;
        }

        return $this->ax->transportName($this->operation($source));
    }

    private function operation(string $source): string
    {
        $def = FieldCatalog::LOOKUP_SOURCES[$source] ?? BpmOperations::customLookupSources()[$source] ?? null;

        return $def['operation'] ?? throw new IntegrationUnavailable('Fonte de consulta inexistente.', 'not_configured');
    }

    private function endpoint(string $source): IntegrationEndpoint
    {
        $endpoint = IntegrationEndpoint::with('connection')->where('key', substr($source, strlen(FieldCatalog::API_PREFIX)))->first();
        if (! $endpoint || $endpoint->method !== 'GET' || empty($endpoint->lookup['expose'])) {
            throw new IntegrationUnavailable('Consulta não publicada no catálogo de integrações.', 'not_configured');
        }

        return $endpoint;
    }
}
