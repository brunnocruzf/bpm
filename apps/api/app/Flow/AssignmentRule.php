<?php

namespace App\Flow;

use App\Forms\FormioSchema;
use App\Models\ProcessStageVersion;

/**
 * Regra de atribuição da etapa ("Quem executa?"), guardada em config.assignment e resolvida pelo Laravel
 * ao criar a tarefa. Sem a chave vale o modo group: a raia é o grupo candidato (ADR-0020).
 */
final class AssignmentRule
{
    public const GROUP = 'group';

    public const FIELD = 'field';

    public const REQUESTER = 'requester';

    public const REQUESTER_MANAGER = 'requester_manager';

    public const MODES = [self::GROUP, self::FIELD, self::REQUESTER, self::REQUESTER_MANAGER];

    private const GROUP_PATTERN = '/^[A-Z][A-Z0-9_]{1,39}$/';

    /** Regra normalizada da etapa; null no modo group (comportamento padrão). */
    public static function of(ProcessStageVersion $stage): ?array
    {
        return self::normalize($stage->configValue('assignment'));
    }

    public static function isValidMode(mixed $rule): bool
    {
        return ! is_array($rule) || in_array($rule['mode'] ?? null, self::MODES, true);
    }

    /**
     * Mantém só as chaves conhecidas. Modo group ou desconhecido devolve null (quem precisa recusar
     * modo desconhecido usa isValidMode antes).
     *
     * @return array<string,mixed>|null
     */
    public static function normalize(mixed $rule): ?array
    {
        if (! is_array($rule) || ! in_array($rule['mode'] ?? null, [self::FIELD, self::REQUESTER, self::REQUESTER_MANAGER], true)) {
            return null;
        }
        $mode = $rule['mode'];
        $fallback = (string) ($rule['fallbackGroup'] ?? '');
        $out = ['mode' => $mode, 'fallbackGroup' => preg_match(self::GROUP_PATTERN, $fallback) ? $fallback : null];
        if ($mode === self::FIELD) {
            $out['fields'] = self::keys($rule['fields'] ?? []);
            $out['requiredUnless'] = self::conditions($rule['requiredUnless'] ?? []);
        }
        if ($mode !== self::REQUESTER) {
            // no modo requester excluir o solicitante seria contraditório
            $out['excludeRequester'] = ! empty($rule['excludeRequester']);
            $out['excludeFields'] = self::keys($rule['excludeFields'] ?? []);
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== [] && $v !== false);
    }

    /** @return list<string> */
    private static function keys(mixed $list): array
    {
        $keys = array_filter(array_map('strval', array_filter((array) $list, 'is_scalar')), fn ($k) => preg_match(FormioSchema::KEY_PATTERN, $k) === 1);

        return array_values(array_unique($keys));
    }

    /** @return array<string,string|int|float|bool> */
    private static function conditions(mixed $map): array
    {
        $out = [];
        foreach ((array) $map as $key => $value) {
            if (is_scalar($value) && preg_match(FormioSchema::KEY_PATTERN, (string) $key) && (! is_string($value) || preg_match('/^[\w .\-]{1,100}$/u', $value))) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }
}
