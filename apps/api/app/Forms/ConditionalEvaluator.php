<?php

namespace App\Forms;

/**
 * Avalia condicionais de visibilidade Form.io (formato simples e formato de condições do v5)
 * no servidor, para que campos ocultos não sejam exigidos nem aceitos.
 */
final class ConditionalEvaluator
{
    /**
     * @param  array<string,mixed>  $component
     * @param  array<string,mixed>  $data  dados raiz
     * @param  array<string,mixed>|null  $row  linha atual (datagrid)
     */
    public static function isVisible(array $component, array $data, ?array $row = null): bool
    {
        if (! empty($component['hidden'])) {
            return false;
        }
        $cond = $component['conditional'] ?? null;
        if (! is_array($cond)) {
            return true;
        }
        $show = $cond['show'] ?? null;
        if ($show === null || $show === '') {
            return true;
        }
        $show = filter_var($show, FILTER_VALIDATE_BOOLEAN);

        if (! empty($cond['conditions']) && is_array($cond['conditions'])) {
            $results = [];
            foreach ($cond['conditions'] as $rule) {
                $value = self::resolve((string) ($rule['component'] ?? ''), $data, $row);
                $results[] = self::test((string) ($rule['operator'] ?? ''), $value, $rule['value'] ?? null);
            }
            $met = ($cond['conjunction'] ?? 'all') === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);

            return $show ? $met : ! $met;
        }

        if (! empty($cond['when'])) {
            $value = self::resolve((string) $cond['when'], $data, $row);
            $met = self::looseEquals($value, $cond['eq'] ?? '');

            return $show ? $met : ! $met;
        }

        return true;
    }

    public static function resolve(string $path, array $data, ?array $row): mixed
    {
        $path = preg_replace('/^data\./', '', $path) ?? $path;
        if (str_contains($path, '.')) {
            // "acoes.tipo" → campo da linha atual do repetidor
            $last = substr($path, strrpos($path, '.') + 1);
            if ($row !== null && array_key_exists($last, $row)) {
                return $row[$last];
            }

            return data_get($data, $path);
        }
        if ($row !== null && array_key_exists($path, $row)) {
            return $row[$path];
        }

        return $data[$path] ?? null;
    }

    public static function test(string $op, mixed $actual, mixed $expected): bool
    {
        return match ($op) {
            'isEqual' => self::looseEquals($actual, $expected),
            'isNotEqual' => ! self::looseEquals($actual, $expected),
            'isEmpty' => self::isEmpty($actual),
            'isNotEmpty' => ! self::isEmpty($actual),
            'includes' => self::includes($actual, $expected),
            'notIncludes' => ! self::includes($actual, $expected),
            'lessThan' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'greaterThan' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'lessThanOrEqual' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'greaterThanOrEqual' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            default => false,
        };
    }

    public static function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            // lista (select múltiplo/datagrid) vazia, ou selectboxes sem nenhuma opção marcada
            return array_is_list($value) ? $value === [] : ! in_array(true, $value, true);
        }

        return $value === null || $value === '' || $value === false;
    }

    private static function looseEquals(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            // selectboxes: {"a": true} ; select múltiplo: ["a"]
            return array_is_list($actual) ? in_array((string) $expected, array_map('strval', $actual), true) : ! empty($actual[(string) $expected]);
        }
        if (is_bool($actual)) {
            return $actual === filter_var($expected, FILTER_VALIDATE_BOOLEAN);
        }

        return (string) ($actual ?? '') === (string) ($expected ?? '');
    }

    private static function includes(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return self::looseEquals($actual, $expected);
        }

        return $expected !== null && $expected !== '' && str_contains((string) $actual, (string) $expected);
    }
}
