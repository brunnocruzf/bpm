<?php

namespace App\Flow;

use App\Integrations\BpmOperations;

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
        'reprovado' => 'Reprovado',
        'cancelado' => 'Cancelado',
        'encerrado_sem_acao' => 'Encerrado sem ação',
        'encerramento_administrativo' => 'Encerramento administrativo',
    ];

    /**
     * Operações fixas + etapas automáticas criadas em Integrações → Mapeamentos ("map:<chave>").
     *
     * @return array<string, array{label:string, system:string, axOperation:string, inputs:list<string>, outputs:list<string>, active?:bool}>
     */
    public static function serviceOperations(): array
    {
        return self::SERVICE_OPERATIONS + BpmOperations::customServiceOperations();
    }

    /** @return array<string,mixed> */
    public static function describe(): array
    {
        $ops = self::serviceOperations();

        return [
            'serviceOperations' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v['label'], 'inputs' => $v['inputs'], 'outputs' => $v['outputs'], 'active' => $v['active'] ?? true], array_keys($ops), $ops),
            'endResults' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys(self::END_RESULTS), self::END_RESULTS),
            'conditionOperators' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys(ConditionCompiler::OPERATORS), ConditionCompiler::OPERATORS),
        ];
    }
}
