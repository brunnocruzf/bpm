<?php

namespace App\Branding;

/**
 * Contraste WCAG 2.x (luminância relativa). Usado no servidor e espelhado na UI
 * (apps/web/src/composables/contrast.ts — mesmas regras e mesmos derivados).
 *
 * Cores de marca claras (ex.: #EE753F) são aceitas: o sistema deriva automaticamente
 *  - o texto sobre a cor (branco ou tinta escura, o que tiver mais contraste) para botões/marca;
 *  - um tom escurecido da primária para texto/links/títulos sobre fundo branco.
 * Só é recusada a cor em que nem branco nem tinta escura atingem 4,5:1 (tons médios).
 */
final class Contrast
{
    public const WHITE = '#ffffff';

    /** Tinta escura do sistema de design (--ink). */
    public const INK = '#172230';

    public const TEXT_MIN = 4.5;

    /** Texto/links derivados miram 5:1 para manter AA também sobre os fundos tingidos (brand-soft). */
    public const TEXT_TARGET = 5.0;

    public static function isHex(string $color): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
    }

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return round((max($la, $lb) + 0.05) / (min($la, $lb) + 0.05), 2);
    }

    public static function luminance(string $hex): float
    {
        $rgb = array_map(fn ($c) => hexdec($c) / 255, str_split(ltrim($hex, '#'), 2));
        $lin = array_map(fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $rgb);

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    /** @return array{0:string,1:float} cor de texto (branco ou tinta) com maior contraste sobre $bg */
    public static function bestForeground(string $bg): array
    {
        $white = self::ratio($bg, self::WHITE);
        $ink = self::ratio($bg, self::INK);

        return $white >= $ink ? [self::WHITE, $white] : [self::INK, $ink];
    }

    /** Mistura com preto (t = 0..1). */
    public static function darken(string $hex, float $t): string
    {
        $rgb = array_map(fn ($c) => (int) round(hexdec($c) * (1 - $t)), str_split(ltrim($hex, '#'), 2));

        return sprintf('#%02x%02x%02x', ...$rgb);
    }

    /** Mistura com branco (t = 0..1). */
    public static function lighten(string $hex, float $t): string
    {
        $rgb = array_map(fn ($c) => (int) round(hexdec($c) + (255 - hexdec($c)) * $t), str_split(ltrim($hex, '#'), 2));

        return sprintf('#%02x%02x%02x', ...$rgb);
    }

    /** Menor escurecimento da cor que atinge $min de contraste sobre $bg (a própria cor, se já atinge). */
    public static function darkenFor(string $hex, string $bg = self::WHITE, float $min = self::TEXT_TARGET): string
    {
        for ($i = 0; $i <= 50; $i++) {
            $candidate = self::darken($hex, $i / 50);
            if (self::ratio($candidate, $bg) >= $min) {
                return $candidate;
            }
        }

        return '#000000';
    }

    /**
     * Tokens derivados aplicados pela UI (e usados no PDF):
     * onPrimary/onSecondary = texto sobre a cor; primaryText = texto/links sobre branco;
     * primaryHover = fundo do botão em hover mantendo o contraste do texto.
     *
     * @param  array{primary:string, secondary:string, accent:string}  $colors
     * @return array{onPrimary:string, onSecondary:string, primaryText:string, primaryHover:string}
     */
    public static function derived(array $colors): array
    {
        [$onPrimary] = self::bestForeground($colors['primary']);
        [$onSecondary] = self::bestForeground($colors['secondary']);

        return [
            'onPrimary' => $onPrimary,
            'onSecondary' => $onSecondary,
            'primaryText' => self::darkenFor($colors['primary']),
            // texto claro → hover mais escuro; texto escuro → hover mais claro
            'primaryHover' => $onPrimary === self::WHITE ? self::darken($colors['primary'], 0.18) : self::lighten($colors['primary'], 0.18),
        ];
    }

    /**
     * Regras: primária e secundária precisam de um texto (branco ou tinta escura) com 4,5:1 sobre
     * elas (botões, marca); o texto/link derivado da primária tem ≥ 4,5:1 sobre branco; o destaque é
     * usado em anel de foco/indicadores sobre branco → 3:1 (componentes de UI, WCAG 1.4.11).
     *
     * @param  array{primary:string, secondary:string, accent:string}  $colors
     * @return list<array{token:string, pair:string, ratio:float, required:float, ok:bool, foreground?:string}>
     */
    public static function check(array $colors): array
    {
        $rows = [];
        foreach (['primary' => 'texto sobre a cor (botões) — branco ou escuro, automático', 'secondary' => 'texto sobre a cor (marca) — branco ou escuro, automático'] as $token => $pair) {
            $color = $colors[$token];
            [$fg, $ratio] = self::isHex($color) ? self::bestForeground($color) : [self::WHITE, 0.0];
            $rows[] = ['token' => $token, 'pair' => $pair, 'ratio' => $ratio, 'required' => self::TEXT_MIN, 'ok' => $ratio >= self::TEXT_MIN, 'foreground' => $fg];
        }
        if (self::isHex($colors['primary'])) {
            $text = self::darkenFor($colors['primary']);
            $ratio = self::ratio($text, self::WHITE);
            $rows[] = ['token' => 'primaryText', 'pair' => 'links e títulos sobre branco (tom derivado '.$text.')', 'ratio' => $ratio, 'required' => self::TEXT_MIN, 'ok' => $ratio >= self::TEXT_MIN, 'foreground' => $text];
        }
        $accent = $colors['accent'];
        $ratio = self::isHex($accent) ? self::ratio($accent, self::WHITE) : 0.0;
        $rows[] = ['token' => 'accent', 'pair' => 'anel de foco e indicadores sobre branco', 'ratio' => $ratio, 'required' => 3.0, 'ok' => $ratio >= 3.0];

        return $rows;
    }
}
