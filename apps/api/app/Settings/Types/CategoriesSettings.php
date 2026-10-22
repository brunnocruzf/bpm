<?php

namespace App\Settings\Types;

use App\Models\User;
use App\Settings\SettingType;
use App\Support\ApiProblem;

/**
 * Áreas (categorias) do catálogo de fluxos: lista curada usada para navegação/filtro,
 * sem relação com grupos/raias de execução (autorização). "Outros" é o fallback fixo.
 */
class CategoriesSettings extends SettingType
{
    public const KEY = 'catalog.categories';

    public const FALLBACK = 'Outros';

    /** Semente usada apenas quando nada foi gravado ainda (instalação nova). */
    private const SEED = ['Qualidade', 'Compras', 'Operações', 'Financeiro'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Áreas do catálogo';
    }

    public function defaults(): array
    {
        // listas sempre têm default vazio: o merge genérico (array_replace_recursive) funde por índice
        // e corrompe uma lista menor que o default; a semente inicial é aplicada em present().
        return ['items' => []];
    }

    public function present(array $value): array
    {
        return $value['items'] ? $value : ['items' => [...self::SEED, self::FALLBACK]];
    }

    public function normalize(array $input, array $current, User $by): array
    {
        $errors = [];
        $items = $input['items'] ?? null;
        if (! is_array($items)) {
            $errors['items'][] = 'Informe a lista de áreas.';
            throw ApiProblem::validation($errors);
        }
        $clean = [];
        foreach ($items as $item) {
            $name = mb_substr(trim(strip_tags((string) $item)), 0, 40);
            if ($name === '' || $name === self::FALLBACK) {
                continue;
            }
            $clean[mb_strtolower($name)] ??= $name; // mantém a grafia da primeira ocorrência
        }
        $clean = array_values($clean);
        if (count($clean) === 0) {
            $errors['items'][] = 'Informe ao menos uma área.';
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return ['items' => [...$clean, self::FALLBACK]];
    }

    public function statusAfterSave(array $value): array
    {
        return ['ok', null];
    }
}
