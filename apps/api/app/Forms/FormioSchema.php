<?php

namespace App\Forms;

/**
 * Utilitários para navegar em schemas Form.io (subset suportado pelo produto).
 */
final class FormioSchema
{
    /** Componentes de layout: não guardam dado, apenas agrupam. */
    public const LAYOUT = ['panel', 'fieldset', 'well', 'columns'];

    /** Componentes de conteúdo estático. */
    public const STATIC = ['content', 'htmlelement'];

    /** Componentes que produzem dado. */
    public const INPUTS = [
        'textfield', 'textarea', 'number', 'currency', 'checkbox', 'selectboxes', 'select', 'radio',
        'email', 'phoneNumber', 'datetime', 'file', 'datagrid', 'axlookup',
    ];

    public const SCALAR_INPUTS = ['textfield', 'textarea', 'number', 'currency', 'checkbox', 'select', 'radio', 'email', 'phoneNumber', 'datetime', 'axlookup'];

    public const RESERVED_KEYS = ['submit', 'initiator', 'data', 'state', 'metadata', 'id', 'row'];

    public const KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,62}$/';

    /** @return list<string> */
    public static function allowedTypes(): array
    {
        return [...self::LAYOUT, ...self::STATIC, ...self::INPUTS];
    }

    /**
     * Filhos diretos de um componente de layout.
     *
     * @param  array<string,mixed>  $component
     * @return list<array<string,mixed>>
     */
    public static function children(array $component): array
    {
        $type = $component['type'] ?? '';
        if ($type === 'columns') {
            $out = [];
            foreach ((array) ($component['columns'] ?? []) as $col) {
                foreach ((array) ($col['components'] ?? []) as $c) {
                    $out[] = $c;
                }
            }

            return $out;
        }

        return array_values((array) ($component['components'] ?? []));
    }

    /**
     * Componentes de dado no escopo atual (desce em layouts, não desce em datagrid).
     *
     * @param  list<array<string,mixed>>  $components
     * @return list<array<string,mixed>>
     */
    public static function dataComponents(array $components): array
    {
        $out = [];
        foreach ($components as $component) {
            $type = $component['type'] ?? '';
            if (in_array($type, self::LAYOUT, true)) {
                array_push($out, ...self::dataComponents(self::children($component)));
            } elseif (in_array($type, self::INPUTS, true)) {
                $out[] = $component;
            }
        }

        return $out;
    }

    /**
     * Mapa key => metadados de cada campo do formulário (inclui filhos de datagrid em 'children').
     *
     * @param  array<string,mixed>  $schema
     * @return array<string, array{type:string,label:string,values:list<string>,disabled:bool,auto:?string,children:array<string,mixed>}>
     */
    public static function fieldMap(array $schema): array
    {
        $map = [];
        foreach (self::dataComponents((array) ($schema['components'] ?? [])) as $c) {
            $map[$c['key']] = self::describe($c);
        }

        return $map;
    }

    /** @param array<string,mixed> $c */
    public static function describe(array $c): array
    {
        $children = [];
        if (($c['type'] ?? '') === 'datagrid') {
            foreach (self::dataComponents((array) ($c['components'] ?? [])) as $child) {
                $children[$child['key']] = self::describe($child);
            }
        }

        return [
            'type' => (string) $c['type'],
            'label' => (string) ($c['label'] ?? $c['key']),
            'values' => self::optionValues($c),
            'disabled' => (bool) ($c['disabled'] ?? false),
            'auto' => isset($c['properties']['tmsaAuto']) ? (string) $c['properties']['tmsaAuto'] : null,
            'children' => $children,
        ];
    }

    /**
     * @param  array<string,mixed>  $c
     * @return list<string>
     */
    public static function optionValues(array $c): array
    {
        $type = $c['type'] ?? '';
        $list = match ($type) {
            'radio', 'selectboxes' => (array) ($c['values'] ?? []),
            'select' => (array) ($c['data']['values'] ?? []),
            default => [],
        };

        return array_values(array_map(fn ($o) => (string) ($o['value'] ?? ''), $list));
    }

    /** @param list<array<string,mixed>> $components */
    public static function countComponents(array $components): int
    {
        $n = 0;
        foreach ($components as $c) {
            $n++;
            $n += self::countComponents(self::children($c));
        }

        return $n;
    }

    public static function hash(array $schema): string
    {
        return hash('sha256', json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
