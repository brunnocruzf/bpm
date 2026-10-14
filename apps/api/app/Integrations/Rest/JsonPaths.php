<?php

namespace App\Integrations\Rest;

use App\Support\Masker;

/**
 * Caminhos em JSON no formato "a.b", "linhas[].item" (primeiro elemento) e "linhas[0].item":
 * leitura, escrita (montagem de corpo), achatamento/inferência de campos a partir de um exemplo e
 * mascaramento (o exemplo guardado nunca contém valores reais — só tipo e formato).
 */
final class JsonPaths
{
    /** Chaves que sugerem dado pessoal/sensível: marcadas como sensíveis e mascaradas em logs. */
    private const SENSITIVE = '/(cpf|cnpj|rg$|^rg|pis|nasc|birth|email|e_mail|mail$|telefone|fone|phone|celular|salari|sal[aá]rio|senha|password|token|secret|conta|agencia|ag[eê]ncia|banco|bank|iban|endere|street|logradouro|cep|zip|numcpf|datnas|cnh|passaporte)/i';

    public static function get(mixed $data, string $path): mixed
    {
        if ($path === '' || $path === '$') {
            return $data;
        }
        foreach (self::segments($path) as $seg) {
            if ($seg === '[]') {
                if (! is_array($data) || ! array_is_list($data)) {
                    return null;
                }
                $data = $data[0] ?? null;

                continue;
            }
            if (preg_match('/^\[(\d+)\]$/', $seg, $m)) {
                $data = is_array($data) ? ($data[(int) $m[1]] ?? null) : null;

                continue;
            }
            if (! is_array($data) || ! array_key_exists($seg, $data)) {
                // tolera diferença de maiúsculas (APIs .NET costumam variar o case)
                $found = null;
                foreach (is_array($data) ? $data : [] as $k => $v) {
                    if (is_string($k) && strcasecmp($k, $seg) === 0) {
                        $found = [$v];
                        break;
                    }
                }
                if ($found === null) {
                    return null;
                }
                $data = $found[0];

                continue;
            }
            $data = $data[$seg];
        }

        return $data;
    }

    /**
     * Escreve $value em $path (cria objetos/listas no caminho). "[]" = primeiro elemento.
     *
     * @param  array<int|string,mixed>  $data
     */
    public static function set(array &$data, string $path, mixed $value): void
    {
        $ref = &$data;
        $segs = self::segments($path);
        foreach ($segs as $i => $seg) {
            $last = $i === count($segs) - 1;
            $key = $seg === '[]' ? 0 : (preg_match('/^\[(\d+)\]$/', $seg, $m) ? (int) $m[1] : $seg);
            if ($last) {
                $ref[$key] = $value;

                return;
            }
            if (! isset($ref[$key]) || ! is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
    }

    /**
     * Infere os campos de um exemplo de resposta (lista → campos do primeiro elemento).
     *
     * @return array{isList: bool, fields: list<array{path:string, type:string, label:string, sensitive:bool, hint:string}>}
     */
    public static function infer(mixed $sample): array
    {
        $isList = is_array($sample) && array_is_list($sample);
        $root = $isList ? ($sample[0] ?? []) : $sample;
        $fields = [];
        self::walk($root, '', $fields, 0);

        return ['isList' => $isList, 'fields' => $fields];
    }

    /** Estrutura do exemplo sem nenhum valor real (tipos/formatos), para guardar e exibir. */
    public static function maskSample(mixed $value, string $key = '', int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '…';
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return $value === [] ? [] : [self::maskSample($value[0], $key, $depth + 1)];
            }
            $out = [];
            foreach (array_slice($value, 0, 200, true) as $k => $v) {
                $out[$k] = self::maskSample($v, (string) $k, $depth + 1);
            }

            return $out;
        }

        return self::hint($value, $key);
    }

    /**
     * Mascara valores de chaves sensíveis (logs de execução): CPF, e-mail, telefone, endereço etc.
     *
     * @param  list<string>  $extraSensitive  caminhos marcados como sensíveis no endpoint
     */
    public static function maskSensitive(mixed $value, array $extraSensitive = [], string $key = '', int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '…';
        }
        if (is_array($value)) {
            $out = [];
            foreach (array_slice($value, 0, 200, true) as $k => $v) {
                $out[$k] = self::maskSensitive($v, $extraSensitive, (string) $k, $depth + 1);
            }

            return $out;
        }
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (self::isSensitive($key) || in_array($key, $extraSensitive, true) || Masker::isSensitiveKey($key)) {
            return '***';
        }

        return is_string($value) ? mb_substr($value, 0, 300) : $value;
    }

    public static function isSensitive(string $path): bool
    {
        $leaf = (string) preg_replace('/^.*[.\]]/', '', $path);

        return $leaf !== '' && preg_match(self::SENSITIVE, $leaf) === 1;
    }

    /** @return list<string> */
    private static function segments(string $path): array
    {
        $path = (string) preg_replace('/^\$\.?/', '', $path);
        preg_match_all('/\[\d*\]|[^.\[\]]+/', $path, $m);

        return $m[0];
    }

    /** @param list<array{path:string, type:string, label:string, sensitive:bool, hint:string}> $fields */
    private static function walk(mixed $node, string $prefix, array &$fields, int $depth): void
    {
        if (count($fields) >= 300 || $depth > 6) {
            return;
        }
        if (is_array($node) && ! array_is_list($node)) {
            foreach ($node as $k => $v) {
                $path = $prefix === '' ? (string) $k : "$prefix.$k";
                if (is_array($v) && ! array_is_list($v)) {
                    self::walk($v, $path, $fields, $depth + 1);
                } elseif (is_array($v) && array_is_list($v) && isset($v[0]) && is_array($v[0])) {
                    self::walk($v[0], $path.'[]', $fields, $depth + 1);
                } else {
                    $fields[] = ['path' => $path, 'type' => self::typeOf($v), 'label' => self::label((string) $k), 'sensitive' => self::isSensitive($path), 'hint' => self::hint($v, (string) $k)];
                }
            }
        }
    }

    private static function typeOf(mixed $v): string
    {
        return match (true) {
            is_bool($v) => 'boolean',
            is_int($v) => 'integer',
            is_float($v) => 'number',
            is_array($v) => 'array',
            is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}([T ][\d:.]+)?(Z|[+-]\d{2}:?\d{2})?$|^\d{2}\/\d{2}\/\d{4}$/', $v) === 1 => 'date',
            default => 'string',
        };
    }

    /** Descreve o valor sem revelá-lo: "texto (18 car.)", "número (10 dígitos)", "data dd/mm/aaaa". */
    private static function hint(mixed $v, string $key): string
    {
        if ($v === null) {
            return 'nulo';
        }
        if (is_bool($v)) {
            return 'booleano';
        }
        if (is_int($v) || is_float($v)) {
            return 'número ('.strlen((string) abs((int) $v)).' dígito(s))';
        }
        if (is_array($v)) {
            return 'lista';
        }
        $s = (string) $v;
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s)) {
            return 'data dd/mm/aaaa';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return 'data ISO';
        }
        if (preg_match('/^\d+$/', $s)) {
            return 'texto numérico ('.strlen($s).' dígito(s))';
        }

        return 'texto ('.mb_strlen($s).' car.)';
    }

    private static function label(string $key): string
    {
        $words = trim((string) preg_replace('/(?<=[a-z])(?=[A-Z])|[_\-]+/', ' ', $key));

        return mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);
    }
}
