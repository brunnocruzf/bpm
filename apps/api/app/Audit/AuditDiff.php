<?php

namespace App\Audit;

use App\Support\Masker;

/**
 * Diff estruturado antes/depois (preferível à cópia de payload). Valores de chaves sensíveis
 * nunca aparecem: registra-se apenas que foram alterados.
 */
final class AuditDiff
{
    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<string, array{from:mixed,to:mixed}>
     */
    public static function between(array $before, array $after, string $prefix = '', int $depth = 0): array
    {
        $changes = [];
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        foreach ($keys as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $from = $before[$key] ?? null;
            $to = $after[$key] ?? null;
            if ($from === $to) {
                continue;
            }
            if (Masker::isSensitiveKey((string) $key)) {
                $changes[$path] = ['from' => $from === null ? null : '***', 'to' => $to === null ? null : '*** (alterado)'];

                continue;
            }
            if ($depth < 4 && self::isAssoc($from) && self::isAssoc($to)) {
                $changes += self::between($from, $to, $path, $depth + 1);

                continue;
            }
            $changes[$path] = ['from' => self::clip($from), 'to' => self::clip($to)];
        }

        return $changes;
    }

    private static function isAssoc(mixed $v): bool
    {
        return is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1);
    }

    private static function clip(mixed $v): mixed
    {
        if (is_string($v)) {
            $v = Masker::scrub($v);

            return mb_strlen($v) > 300 ? mb_substr($v, 0, 300).'…' : $v;
        }
        if (is_array($v)) {
            $encoded = (string) json_encode(Masker::mask($v), JSON_UNESCAPED_UNICODE);

            return strlen($encoded) > 1000 ? '[lista/objeto com '.count($v).' itens]' : Masker::mask($v);
        }

        return $v;
    }
}
