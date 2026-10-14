<?php

namespace App\Flow;

/** Operações de integração e resultados de encerramento suportados pelo produto. */
final class StageCatalog
{
    /**
     * Service tasks executadas pelo worker Laravel (Flowable external worker).
     * inputs: chaves do contexto enviadas ao adaptador; outputs: chaves gravadas de volta.
     */
    public const SERVICE_OPERATIONS = [
        'ax.transferAndBlock' => [
            'label' => 'AX: diário de transferência, marcação da OCDev na OC e bloqueio do item',
            'system' => 'AX',
            'axOperation' => 'transferAndBlock',
            'inputs' => ['numeroOcdev', 'codigoItem', 'ordemCompra'],
            'outputs' => ['numeroDiarioTransferencia', 'ocdevMarcadaNaOc', 'itemBloqueado'],
        ],
    ];

    public const END_RESULTS = [
        'concluido' => 'Concluído',
        'nao_procedente' => 'Encerrado — não procedente',
        'cancelado' => 'Cancelado',
        'encerrado_sem_acao' => 'Encerrado sem ação',
        'encerramento_administrativo' => 'Encerramento administrativo',
    ];

    /** @return array<string,mixed> */
    public static function describe(): array
    {
        return [
            'serviceOperations' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v['label'], 'outputs' => $v['outputs']], array_keys(self::SERVICE_OPERATIONS), self::SERVICE_OPERATIONS),
            'endResults' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys(self::END_RESULTS), self::END_RESULTS),
            'conditionOperators' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys(ConditionCompiler::OPERATORS), ConditionCompiler::OPERATORS),
        ];
    }
}
