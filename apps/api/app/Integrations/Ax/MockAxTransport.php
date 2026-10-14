<?php

namespace App\Integrations\Ax;

use App\Integrations\IntegrationUnavailable;
use Illuminate\Support\Facades\Cache;

/**
 * Transporte simulado com dados SINTÉTICOS (nenhum dado real da TMSA).
 * Convenções para testes manuais:
 *  - valores iniciados por "NAOEXISTE" → não encontrado;
 *  - valores iniciados por "INDISPONIVEL" → simula AX fora do ar.
 */
class MockAxTransport implements AxTransport
{
    private const CUSTOMERS = ['Cliente Exemplo Ltda (fictício)', 'Montadora Demonstração S.A. (fictício)', 'Indústria Modelo Ltda (fictício)'];

    private const MATERIALS = ['Chapa de aço 2mm', 'Rolamento 6205', 'Parafuso M8 x 30', 'Retentor 40x62', 'Tubo trefilado 1"'];

    private const SUPPLIERS = [
        ['code' => 'F-1001', 'name' => 'Fornecedor Alfa (fictício)', 'contact' => 'Contato Alfa', 'phone' => '(11) 0000-1001', 'email' => 'qualidade@alfa.example'],
        ['code' => 'F-1002', 'name' => 'Fornecedor Beta (fictício)', 'contact' => 'Contato Beta', 'phone' => '(11) 0000-1002', 'email' => 'sac@beta.example'],
        ['code' => 'F-1003', 'name' => 'Fornecedor Gama (fictício)', 'contact' => 'Contato Gama', 'phone' => '(11) 0000-1003', 'email' => 'rnc@gama.example'],
    ];

    public function name(): string
    {
        return 'MOCK';
    }

    public function lookup(string $operation, string $value): ?array
    {
        $value = trim($value);
        if (str_starts_with(strtoupper($value), 'INDISPONIVEL')) {
            throw new IntegrationUnavailable('AX (simulado) indisponível.', 'timeout');
        }
        if ($value === '' || str_starts_with(strtoupper($value), 'NAOEXISTE')) {
            return null;
        }
        $n = abs(crc32(strtoupper($value)));

        return match ($operation) {
            'tpr.lookup' => ['customerCode' => 'C-'.str_pad((string) ($n % 9000 + 1000), 4, '0', STR_PAD_LEFT), 'customerName' => self::CUSTOMERS[$n % count(self::CUSTOMERS)]],
            'item.lookup' => ['itemDescription' => self::MATERIALS[$n % count(self::MATERIALS)], 'unit' => $n % 2 ? 'PC' : 'KG'],
            'purchaseOrder.lookup' => (function () use ($n) {
                $s = self::SUPPLIERS[$n % count(self::SUPPLIERS)];

                return ['supplierCode' => $s['code'], 'supplierName' => $s['name'], 'status' => 'Recebida parcialmente'];
            })(),
            'supplier.lookup' => (function () use ($value) {
                foreach (self::SUPPLIERS as $s) {
                    if (strcasecmp($s['code'], $value) === 0) {
                        return ['supplierName' => $s['name'], 'contactName' => $s['contact'], 'phone' => $s['phone'], 'email' => $s['email']];
                    }
                }

                return null;
            })(),
            default => throw new IntegrationUnavailable("Operação $operation não suportada pelo mock.", 'not_configured'),
        };
    }

    public function execute(string $operation, array $payload, string $idempotencyKey): array
    {
        // Idempotência: a mesma chave devolve o mesmo resultado.
        return Cache::rememberForever('mock-ax:'.$idempotencyKey, function () use ($operation, $payload) {
            return match ($operation) {
                'transferAndBlock' => [
                    'numeroDiarioTransferencia' => 'DT-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                    'ocdevMarcadaNaOc' => true,
                    'itemBloqueado' => true,
                    'axReferencia' => (string) ($payload['numeroOcdev'] ?? ''),
                ],
                default => throw new IntegrationUnavailable("Operação $operation não suportada pelo mock.", 'not_configured'),
            };
        });
    }
}
