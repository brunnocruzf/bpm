<?php

namespace App\Reports\Pdf;

/**
 * Métricas da Helvetica (AFM padrão, unidades de 1/1000 em) para os códigos WinAnsi usados.
 * Negrito é aproximado com fator de alargamento (superestima levemente: quebra segura).
 */
final class Metrics
{
    private const BOLD_FACTOR = 1.07;

    /** @var array<int,int>|null */
    private static ?array $widths = null;

    /** Converte UTF-8 para Windows-1252 (caracteres fora do conjunto viram aproximação ASCII). */
    public static function encode(string $text): string
    {
        $text = strtr($text, ['→' => '->', '←' => '<-', '✓' => 'v', '✔' => 'v', '✗' => 'x', '≥' => '>=', '≤' => '<=', '×' => 'x', "\t" => ' ']);
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return $out === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $text) : $out;
    }

    public static function width(string $utf8, float $size, bool $bold = false): float
    {
        $w = 0;
        $map = self::map();
        foreach (str_split(self::encode($utf8)) as $ch) {
            $w += $map[ord($ch)] ?? 556;
        }

        return $w * $size / 1000 * ($bold ? self::BOLD_FACTOR : 1);
    }

    /** @return list<string> linhas (UTF-8) que cabem em $max pontos */
    public static function wrap(string $text, float $size, float $max, bool $bold = false): array
    {
        $text = trim((string) preg_replace('/[ \t]+/', ' ', $text));
        if ($text === '') {
            return [''];
        }
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $paragraph) {
            $current = '';
            foreach (explode(' ', $paragraph) as $word) {
                $candidate = $current === '' ? $word : "$current $word";
                if (self::width($candidate, $size, $bold) <= $max) {
                    $current = $candidate;

                    continue;
                }
                if ($current !== '') {
                    $lines[] = $current;
                }
                // palavra maior que a linha: quebra por caractere
                while (self::width($word, $size, $bold) > $max && mb_strlen($word) > 1) {
                    $cut = mb_strlen($word);
                    while ($cut > 1 && self::width(mb_substr($word, 0, $cut), $size, $bold) > $max) {
                        $cut--;
                    }
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $current = $word;
            }
            $lines[] = $current;
        }

        return $lines;
    }

    /** @return array<int,int> */
    private static function map(): array
    {
        if (self::$widths !== null) {
            return self::$widths;
        }
        $ascii = [
            32 => 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
        ];
        $map = $ascii;
        $extra = [
            0x80 => 556, 0x82 => 222, 0x84 => 333, 0x85 => 1000, 0x91 => 222, 0x92 => 222, 0x93 => 333, 0x94 => 333,
            0x95 => 350, 0x96 => 556, 0x97 => 1000, 0x99 => 1000, 0xA0 => 278, 0xAA => 370, 0xB0 => 400, 0xB7 => 278,
            0xBA => 365, 0xAB => 556, 0xBB => 556, 0xA7 => 556, 0xA9 => 737, 0xAE => 737,
        ];
        foreach ($extra as $k => $v) {
            $map[$k] = $v;
        }
        // Latin-1 acentuadas seguem a largura da letra base
        foreach ([0xC0, 0xC1, 0xC2, 0xC3, 0xC4, 0xC5] as $c) {
            $map[$c] = 667;
        }
        $map[0xC6] = 1000;
        $map[0xC7] = 722;
        foreach ([0xC8, 0xC9, 0xCA, 0xCB] as $c) {
            $map[$c] = 667;
        }
        foreach ([0xCC, 0xCD, 0xCE, 0xCF] as $c) {
            $map[$c] = 278;
        }
        $map[0xD0] = 722;
        $map[0xD1] = 722;
        foreach ([0xD2, 0xD3, 0xD4, 0xD5, 0xD6, 0xD8] as $c) {
            $map[$c] = 778;
        }
        $map[0xD7] = 584;
        foreach ([0xD9, 0xDA, 0xDB, 0xDC] as $c) {
            $map[$c] = 722;
        }
        $map[0xDD] = 667;
        $map[0xDE] = 667;
        $map[0xDF] = 611;
        foreach ([0xE0, 0xE1, 0xE2, 0xE3, 0xE4, 0xE5] as $c) {
            $map[$c] = 556;
        }
        $map[0xE6] = 889;
        $map[0xE7] = 500;
        foreach ([0xE8, 0xE9, 0xEA, 0xEB, 0xF0, 0xF1, 0xF2, 0xF3, 0xF4, 0xF5, 0xF6, 0xF9, 0xFA, 0xFB, 0xFC, 0xFE] as $c) {
            $map[$c] = 556;
        }
        foreach ([0xEC, 0xED, 0xEE, 0xEF] as $c) {
            $map[$c] = 278;
        }
        $map[0xF7] = 584;
        $map[0xF8] = 611;
        $map[0xFD] = 500;
        $map[0xFF] = 500;

        return self::$widths = $map;
    }
}
