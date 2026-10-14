<?php

namespace App\Integrations\Ax;

use App\Integrations\IntegrationUnavailable;

/**
 * Placeholder para REST_FACADE, AIF_WCF e SQL_READONLY_VIEW.
 * Os contratos do AX 2012 R3 (endpoints, bindings, autenticação, views) ainda não
 * foram recebidos; por decisão de arquitetura, nenhum contrato é inventado.
 * Ao receber OpenAPI/WSDL/views aprovadas, implementar um transporte dedicado.
 */
class UnconfiguredAxTransport implements AxTransport
{
    public function __construct(private readonly string $transport) {}

    public function name(): string
    {
        return $this->transport;
    }

    public function lookup(string $operation, string $value): ?array
    {
        throw new IntegrationUnavailable("Transporte {$this->transport} para $operation ainda não configurado (contrato AX pendente).", 'not_configured');
    }

    public function execute(string $operation, array $payload, string $idempotencyKey): array
    {
        throw new IntegrationUnavailable("Transporte {$this->transport} para $operation ainda não configurado (contrato AX pendente).", 'not_configured');
    }
}
