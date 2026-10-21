<?php

namespace App\Integrations;

use App\Flow\StageCatalog;
use App\Forms\FieldCatalog;
use App\Models\IntegrationBinding;
use Throwable;

/**
 * Operações do BPM que podem ser ligadas a um endpoint do catálogo (Integrações → Mapeamentos):
 *  - fixas do PO 159: consultas dos formulários (FieldCatalog) e a tarefa de integração do AX (StageCatalog),
 *    que usam o transporte configurado (MOCK) enquanto não há vínculo ativo;
 *  - personalizadas ("map:<chave>"), criadas pelo administrador: consulta nos formulários (fonte do
 *    componente Consulta AX) ou etapa automática do fluxo (integração executada). Sem vínculo ativo, falham.
 */
final class BpmOperations
{
    public const LOOKUP_INPUT = ['value' => 'Valor digitado no formulário'];

    /**
     * @return list<array{operation:string,label:string,kind:string,inputs:array<string,string>,outputs:array<string,string>,custom:bool,description:?string,lookupSource?:string,serviceOperation?:string}>
     */
    public static function all(): array
    {
        $ops = [];
        foreach (FieldCatalog::LOOKUP_SOURCES as $key => $def) {
            $ops[] = ['operation' => $def['operation'], 'label' => 'Consulta nos formulários: '.$def['label'], 'kind' => 'lookup', 'inputs' => self::LOOKUP_INPUT,
                'outputs' => $def['fields'], 'custom' => false, 'description' => null, 'lookupSource' => $key];
        }
        foreach (StageCatalog::SERVICE_OPERATIONS as $key => $def) {
            $ops[] = ['operation' => $def['axOperation'], 'label' => 'Tarefa de integração: '.$def['label'], 'kind' => 'write', 'inputs' => array_combine($def['inputs'], $def['inputs']),
                'outputs' => array_combine($def['outputs'], $def['outputs']), 'custom' => false, 'description' => null, 'serviceOperation' => $key];
        }
        foreach (self::customBindings() as $b) {
            $ops[] = ['operation' => $b->operation, 'label' => (string) $b->label, 'kind' => (string) $b->kind, 'inputs' => $b->inputsMap(), 'outputs' => $b->outputsMap(),
                'custom' => true, 'description' => $b->description, ($b->kind === 'lookup' ? 'lookupSource' : 'serviceOperation') => $b->operation];
        }

        return $ops;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $operation): ?array
    {
        foreach (self::all() as $op) {
            if ($op['operation'] === $operation) {
                return $op;
            }
        }

        return null;
    }

    /** Operação fixa (tem transporte configurado, MOCK por padrão)? */
    public static function isFixed(string $operation): bool
    {
        return array_key_exists($operation, (array) config('bpm.ax.transports'));
    }

    /**
     * Fontes do componente Consulta AX criadas em Mapeamentos (chave = "map:<chave>").
     *
     * @return array<string, array{label:string, operation:string, fields:array<string,string>, system:string, active:bool}>
     */
    public static function customLookupSources(): array
    {
        $sources = [];
        foreach (self::customBindings() as $b) {
            if ($b->kind !== 'lookup') {
                continue;
            }
            $system = $b->endpoint?->connection->system;
            $sources[$b->operation] = ['label' => (string) $b->label.($system ? " ($system)" : ''), 'operation' => $b->operation, 'fields' => $b->outputsMap(),
                'system' => $system ?: 'sistema externo', 'active' => $b->usable()];
        }

        return $sources;
    }

    /**
     * Integrações executadas pela etapa automática do fluxo criadas em Mapeamentos (chave = "map:<chave>").
     *
     * @return array<string, array{label:string, system:string, axOperation:string, inputs:list<string>, outputs:list<string>, active:bool}>
     */
    public static function customServiceOperations(): array
    {
        $ops = [];
        foreach (self::customBindings() as $b) {
            if ($b->kind !== 'task') {
                continue;
            }
            $ops[$b->operation] = ['label' => (string) $b->label, 'system' => $b->endpoint?->connection->system ?? 'sistema externo', 'axOperation' => $b->operation,
                'inputs' => array_keys($b->inputsMap()), 'outputs' => array_keys($b->outputsMap()), 'active' => $b->usable()];
        }

        return $ops;
    }

    /** @return list<IntegrationBinding> */
    private static function customBindings(): array
    {
        try {
            return IntegrationBinding::custom()->with('endpoint.connection')->orderBy('label')->get()->all();
        } catch (Throwable) {
            return []; // banco indisponível/migração pendente: só as operações fixas
        }
    }
}
