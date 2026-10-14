<?php

namespace App\Integrations\Ax;

interface AxTransport
{
    public function name(): string;

    /**
     * Consulta (somente leitura).
     *
     * @return array<string,mixed>|null null = registro não encontrado
     */
    public function lookup(string $operation, string $value): ?array;

    /**
     * Operação de escrita de negócio (diário, OCDev, bloqueio). Deve ser idempotente pela chave.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function execute(string $operation, array $payload, string $idempotencyKey): array;
}
