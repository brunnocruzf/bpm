<?php

namespace App\Forms;

use App\Identity\EmployeeDirectory;
use App\Integrations\BpmOperations;
use App\Models\IntegrationEndpoint;

/**
 * Catálogo de extensões TMSA permitidas nos formulários:
 *  - properties.tmsaAuto: campo preenchido pelo servidor (nunca pelo cliente);
 *  - componente "axlookup": consulta ao AX com preenchimento de campos destino.
 */
final class FieldCatalog
{
    public const AUTO_SOURCES = [
        'user.name' => 'Usuário logado — nome',
        'user.area' => 'Usuário logado — área',
        'user.email' => 'Usuário logado — e-mail',
        'user.registration' => 'Usuário logado — matrícula',
        'now.date' => 'Data atual',
        'now.datetime' => 'Data e hora atuais',
        'instance.number' => 'Número da solicitação',
        'instance.requesterName' => 'Solicitante — nome',
        'instance.requesterArea' => 'Solicitante — área',
        'instance.startedAt' => 'Data de abertura',
    ];

    /** sequence:RACF → número anual "RACF-000001/26"; counter:X → contador por instância. */
    public const AUTO_PATTERN = '/^(sequence|counter):[A-Z][A-Z0-9_]{1,19}$/';

    /** requester.matches:<referência> → "sim" quando o solicitante é o usuário da referência (calculado no envio). */
    public const REQUESTER_MATCHES = 'requester.matches:';

    /** Prefixo das consultas publicadas no catálogo de integrações (endpoint GET exposto aos formulários). */
    public const API_PREFIX = 'api:';

    public const LOOKUP_SOURCES = [
        'tpr' => [
            'label' => 'TPR → Cliente',
            'operation' => 'tpr.lookup',
            'fields' => ['customerCode' => 'Código do cliente', 'customerName' => 'Cliente'],
        ],
        'item' => [
            'label' => 'Código do item → Material',
            'operation' => 'item.lookup',
            'fields' => ['itemDescription' => 'Descrição do material', 'unit' => 'Unidade'],
        ],
        'purchaseOrder' => [
            'label' => 'Ordem de compra',
            'operation' => 'purchaseOrder.lookup',
            'fields' => ['supplierCode' => 'Código do fornecedor', 'supplierName' => 'Fornecedor', 'status' => 'Situação da OC'],
        ],
        'supplier' => [
            'label' => 'Código do fornecedor → Contato',
            'operation' => 'supplier.lookup',
            'fields' => ['supplierName' => 'Fornecedor', 'contactName' => 'Contato', 'phone' => 'Telefone', 'email' => 'E-mail'],
        ],
    ];

    /**
     * Consultas internas: leem o próprio banco do BPM (sem integração externa nem vínculo em Mapeamentos).
     *
     * @return array<string, array{label:string, operation:string, fields:array<string,string>, system:string}>
     */
    public static function internalLookupSources(): array
    {
        return [
            EmployeeDirectory::SOURCE => [
                'label' => 'Matrícula → Colaborador e gestor imediato (usuários do Entra)',
                'operation' => 'directory.employee',
                'fields' => EmployeeDirectory::FIELDS,
                'system' => 'cadastro de usuários',
            ],
        ];
    }

    /**
     * Fontes fixas + consultas internas + endpoints publicados como consulta ("api:<identificador>") + consultas
     * criadas em Integrações → Mapeamentos ("map:<chave>").
     *
     * @return array<string, array{label:string, operation:string, fields:array<string,string>, system?:string}>
     */
    public static function lookupSources(): array
    {
        $sources = self::LOOKUP_SOURCES + self::internalLookupSources() + BpmOperations::customLookupSources();
        try {
            foreach (IntegrationEndpoint::with('connection')->where('enabled', true)->where('method', 'GET')->get() as $e) {
                $cfg = (array) ($e->lookup ?? []);
                if (empty($cfg['expose']) || empty($cfg['inputParam']) || empty($cfg['fields'])) {
                    continue;
                }
                $labels = collect((array) ($e->response_fields ?? []))->pluck('label', 'path')->all();
                $fields = [];
                foreach ((array) $cfg['fields'] as $path) {
                    $fields[(string) $path] = (string) ($labels[$path] ?? $path);
                }
                $sources[self::API_PREFIX.$e->key] = ['label' => (string) ($cfg['label'] ?? $e->name).' ('.$e->connection->system.')', 'operation' => 'endpoint:'.$e->key, 'fields' => $fields, 'system' => $e->connection->system];
            }
        } catch (\Throwable) {
            // banco indisponível/migração pendente: somente as fontes fixas
        }

        return $sources;
    }

    public static function isValidAuto(string $value): bool
    {
        if (str_starts_with($value, self::REQUESTER_MATCHES)) {
            return preg_match(FormioSchema::KEY_PATTERN, substr($value, strlen(self::REQUESTER_MATCHES))) === 1;
        }

        return array_key_exists($value, self::AUTO_SOURCES) || preg_match(self::AUTO_PATTERN, $value) === 1;
    }

    /** @return array<string,mixed> */
    public static function describe(): array
    {
        $lookups = [];
        foreach (self::lookupSources() as $key => $def) {
            $lookups[] = ['key' => $key, 'label' => $def['label'], 'system' => $def['system'] ?? 'AX', 'fields' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys($def['fields']), $def['fields'])];
        }

        return [
            'autoSources' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys(self::AUTO_SOURCES), self::AUTO_SOURCES),
            'autoPatterns' => ['sequence:<CHAVE>', 'counter:<CHAVE>', self::REQUESTER_MATCHES.'<referência>'],
            'lookupSources' => $lookups,
            'componentTypes' => FormioSchema::allowedTypes(),
        ];
    }
}
