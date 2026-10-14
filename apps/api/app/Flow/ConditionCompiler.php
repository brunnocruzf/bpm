<?php

namespace App\Flow;

use App\Forms\FormioSchema;
use InvalidArgumentException;

/**
 * DSL de condições em lista branca → expressão Flowable.
 *
 * O designer nunca digita JUEL/JavaScript/SQL. A condição é um JSON:
 *   {"match":"all|any","rules":[{"field":"urgente","operator":"eq","value":"sim"}]}
 * e é compilada para funções seguras do Flowable (vars:equals, vars:isEmpty, ...),
 * que tratam variável inexistente como "falso" em vez de lançar erro.
 * Literais são escapados (\\ e \') — validado contra o Flowable 7.2.
 */
final class ConditionCompiler
{
    public const OPERATORS = [
        'eq' => 'é igual a',
        'neq' => 'é diferente de',
        'empty' => 'está vazio',
        'not_empty' => 'não está vazio',
        'contains' => 'contém',
        'gt' => 'é maior que',
        'gte' => 'é maior ou igual a',
        'lt' => 'é menor que',
        'lte' => 'é menor ou igual a',
    ];

    private const UNARY = ['empty', 'not_empty'];

    private const NUMERIC = ['gt', 'gte', 'lt', 'lte'];

    /**
     * Normaliza e valida a estrutura (sem checar existência dos campos — isso é do FlowValidator).
     *
     * @return array{match:string,rules:list<array{field:string,operator:string,value:mixed}>}|null
     */
    public static function normalize(mixed $condition): ?array
    {
        if ($condition === null || $condition === [] || $condition === '') {
            return null;
        }
        if (! is_array($condition)) {
            throw new InvalidArgumentException('Condição inválida.');
        }
        $rules = $condition['rules'] ?? null;
        if (! is_array($rules) || $rules === []) {
            return null;
        }
        $max = (int) config('bpm.designer.max_condition_rules', 10);
        if (count($rules) > $max) {
            throw new InvalidArgumentException("Uma condição aceita no máximo $max regras.");
        }

        $out = [];
        foreach ($rules as $rule) {
            $field = (string) ($rule['field'] ?? '');
            $operator = (string) ($rule['operator'] ?? '');
            if (! preg_match(FormioSchema::KEY_PATTERN, $field)) {
                throw new InvalidArgumentException('Campo da condição inválido.');
            }
            if (! array_key_exists($operator, self::OPERATORS)) {
                throw new InvalidArgumentException("Operador \"$operator\" não suportado.");
            }
            $value = $rule['value'] ?? null;
            if (in_array($operator, self::UNARY, true)) {
                $value = null;
            } elseif (is_string($value)) {
                if (mb_strlen($value) > 200 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                    throw new InvalidArgumentException('Valor da condição inválido.');
                }
            } elseif (! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                throw new InvalidArgumentException('Valor da condição inválido.');
            }
            if (in_array($operator, self::NUMERIC, true) && ! is_numeric($value)) {
                throw new InvalidArgumentException('Comparações maior/menor exigem valor numérico.');
            }
            $out[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }

        return ['match' => ($condition['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => $out];
    }

    /**
     * @param  array{match:string,rules:list<array{field:string,operator:string,value:mixed}>}  $condition
     * @param  array<string,string>  $fieldTypes  key => tipo Form.io (para tipar literais)
     */
    public static function compile(array $condition, array $fieldTypes = []): string
    {
        $condition = self::normalize($condition) ?? throw new InvalidArgumentException('Condição vazia.');
        $parts = [];
        foreach ($condition['rules'] as $rule) {
            $f = $rule['field'];
            $lit = fn () => self::literal($rule['value'], $fieldTypes[$f] ?? null);
            $parts[] = match ($rule['operator']) {
                'eq' => "vars:equals($f, {$lit()})",
                'neq' => "vars:notEquals($f, {$lit()})",
                'empty' => "vars:isEmpty($f)",
                'not_empty' => "vars:isNotEmpty($f)",
                'contains' => "vars:contains($f, {$lit()})",
                'gt' => "vars:greaterThan($f, ".self::number($rule['value']).')',
                'gte' => "vars:greaterThanOrEquals($f, ".self::number($rule['value']).')',
                'lt' => "vars:lowerThan($f, ".self::number($rule['value']).')',
                'lte' => "vars:lowerThanOrEquals($f, ".self::number($rule['value']).')',
                default => throw new InvalidArgumentException("Operador {$rule['operator']} não suportado."),
            };
        }
        $joiner = $condition['match'] === 'any' ? ' || ' : ' && ';

        return '${'.implode($joiner, $parts).'}';
    }

    /** @param array<string,string> $fieldLabels */
    public static function describe(?array $condition, array $fieldLabels = [], array $valueLabels = []): string
    {
        if (! $condition || empty($condition['rules'])) {
            return '';
        }
        $parts = [];
        foreach ($condition['rules'] as $rule) {
            $label = $fieldLabels[$rule['field']] ?? $rule['field'];
            $op = self::OPERATORS[$rule['operator']] ?? $rule['operator'];
            $value = $rule['value'];
            if (in_array($rule['operator'], self::UNARY, true)) {
                $parts[] = "$label $op";
            } else {
                $shown = $valueLabels[$rule['field']][(string) (is_bool($value) ? ($value ? 'true' : 'false') : $value)] ?? (is_bool($value) ? ($value ? 'Sim' : 'Não') : (string) $value);
                $parts[] = "$label $op \"$shown\"";
            }
        }

        return 'Se '.implode($condition['match'] === 'any' ? ' OU ' : ' E ', $parts);
    }

    private static function literal(mixed $value, ?string $fieldType): string
    {
        if ($fieldType === 'checkbox') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }
        if (in_array($fieldType, ['number', 'currency'], true) && is_numeric($value)) {
            return self::number($value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ((is_int($value) || is_float($value)) && $fieldType === null) {
            return self::number($value);
        }

        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'";
    }

    private static function number(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Valor numérico inválido.');
        }
        $n = $value + 0;

        return is_int($n) ? (string) $n : rtrim(rtrim(number_format((float) $n, 6, '.', ''), '0'), '.');
    }
}
