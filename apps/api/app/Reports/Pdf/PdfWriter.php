<?php

namespace App\Reports\Pdf;

/**
 * Gerador PDF mínimo e sem dependências (código próprio, sem licença de terceiros):
 * A4 retrato, fontes padrão Helvetica (WinAnsi), quebra de linha por métrica AFM, títulos,
 * pares rótulo/valor, tabelas com cabeçalho repetido, cabeçalho/rodapé com "Página X de Y",
 * selo de confidencialidade e logo (PNG/WebP via GD). Limitações documentadas no ADR-0011:
 * sem PDF/UA (tags de acessibilidade) e somente caracteres do Windows-1252.
 */
final class PdfWriter
{
    private const W = 595.28;

    private const H = 841.89;

    private const M = 42.0;

    private const TOP = 88.0;

    private const BOTTOM = 56.0;

    /** @var list<string> */
    private array $pages = [];

    private string $page = '';

    private float $y = 0;

    /** @var array{rgb:string, alpha:?string, w:int, h:int}|null */
    private ?array $logo = null;

    /** @var array{0:float,1:float,2:float} */
    private array $brand;

    /**
     * @param  array{title:string, org:string, subtitle?:string, confidentiality?:string, footer:string, brandColor?:string}  $header
     */
    public function __construct(private readonly array $header, ?string $logoBytes = null)
    {
        $this->brand = self::rgb($header['brandColor'] ?? '#086b77');
        if ($logoBytes) {
            $this->logo = self::decodeImage($logoBytes);
        }
        $this->newPage();
    }

    public function heading(string $text, int $level = 1): void
    {
        $size = $level === 1 ? 14.0 : 11.0;
        $this->ensure($size + 18);
        $this->y += $level === 1 ? 8 : 4;
        $color = $level === 1 ? $this->brand : [0.17, 0.25, 0.33];
        foreach (Metrics::wrap($text, $size, self::W - 2 * self::M, true) as $line) {
            $this->text(self::M, $this->y + $size, $line, $size, 'F2', $color);
            $this->y += $size + 3;
        }
        if ($level === 1) {
            $this->line(self::M, $this->y + 2, self::W - self::M, $this->y + 2, 0.6, [0.85, 0.88, 0.91]);
        }
        $this->y += 6;
    }

    public function paragraph(string $text, string $style = 'normal'): void
    {
        $size = $style === 'small' ? 7.5 : 9.0;
        $font = $style === 'note' ? 'F3' : 'F1';
        $color = $style === 'normal' ? [0.18, 0.24, 0.3] : [0.4, 0.46, 0.53];
        foreach (preg_split('/\R/', $text) ?: [] as $para) {
            foreach (Metrics::wrap($para, $size, self::W - 2 * self::M) as $line) {
                $this->ensure($size + 4);
                $this->text(self::M, $this->y + $size, $line, $size, $font, $color);
                $this->y += $size + 3;
            }
        }
        $this->y += 4;
    }

    /** @param list<array{0:string,1:string}> $rows */
    public function keyValues(array $rows): void
    {
        $this->table([['label' => '', 'width' => 0.3], ['label' => '', 'width' => 0.7]], array_map(fn ($r) => [$r[0], $r[1]], $rows), false, true);
    }

    /**
     * @param  list<array{label:string, width?:float}>  $columns  largura relativa (0..1)
     * @param  list<list<string>>  $rows
     */
    public function table(array $columns, array $rows, bool $header = true, bool $labelColumn = false): void
    {
        $avail = self::W - 2 * self::M;
        $given = array_sum(array_map(fn ($c) => (float) ($c['width'] ?? 0), $columns));
        $free = count(array_filter($columns, fn ($c) => empty($c['width'])));
        $widths = array_map(fn ($c) => $avail * (! empty($c['width']) ? (float) $c['width'] : max(0.05, (1 - $given) / max(1, $free))), $columns);
        $size = 8.0;
        $pad = 4.0;
        $lead = $size + 2.5;

        $drawRow = function (array $cells, bool $isHeader) use ($widths, $size, $pad, $lead, $labelColumn) {
            $wrapped = [];
            $lines = 1;
            foreach ($widths as $i => $w) {
                $bold = $isHeader || ($labelColumn && $i === 0);
                $wrapped[$i] = Metrics::wrap((string) ($cells[$i] ?? ''), $size, $w - 2 * $pad, $bold);
                $lines = max($lines, count($wrapped[$i]));
            }
            $height = $lines * $lead + 2 * $pad - 2;
            if ($this->y + $height > self::H - self::BOTTOM) {
                return false;
            }
            $x = self::M;
            if ($isHeader) {
                $this->rect(self::M, $this->y, array_sum($widths), $height, [0.95, 0.96, 0.97]);
            } elseif ($labelColumn) {
                $this->rect(self::M, $this->y, $widths[0], $height, [0.975, 0.98, 0.985]);
            }
            foreach ($widths as $i => $w) {
                $bold = $isHeader || ($labelColumn && $i === 0);
                $color = $isHeader || ($labelColumn && $i === 0) ? [0.35, 0.42, 0.5] : [0.15, 0.2, 0.27];
                foreach ($wrapped[$i] as $n => $line) {
                    $this->text($x + $pad, $this->y + $pad + $size + $n * $lead - 1, $line, $size, $bold ? 'F2' : 'F1', $color);
                }
                $x += $w;
            }
            $this->line(self::M, $this->y + $height, self::M + array_sum($widths), $this->y + $height, 0.4, [0.85, 0.88, 0.91]);
            $this->y += $height;

            return true;
        };

        $headerCells = array_map(fn ($c) => $c['label'], $columns);
        if ($header) {
            $this->ensure(40);
            $drawRow($headerCells, true);
        }
        foreach ($rows as $row) {
            if (! $drawRow($row, false)) {
                $this->newPage();
                if ($header) {
                    $drawRow($headerCells, true);
                }
                if (! $drawRow($row, false)) {
                    // linha maior que a página: trunca o texto de forma explícita
                    $drawRow(array_map(fn ($c) => mb_substr((string) $c, 0, 1500).' […]', $row), false);
                }
            }
        }
        $this->y += 8;
    }

    public function spacer(float $h = 8): void
    {
        $this->y += $h;
    }

    public function pageBreak(): void
    {
        $this->newPage();
    }

    public function output(): string
    {
        $this->pages[] = $this->page;
        $total = count($this->pages);
        $objects = [];
        $add = function (string $body) use (&$objects): int {
            $objects[] = $body;

            return count($objects);
        };

        $catalogId = $add(''); // preenchido depois
        $pagesId = $add('');
        $f1 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $f2 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $f3 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>');
        $xobj = '';
        if ($this->logo) {
            $smask = null;
            if ($this->logo['alpha'] !== null) {
                $alpha = (string) gzcompress($this->logo['alpha'], 9);
                $smask = $add("<< /Type /XObject /Subtype /Image /Width {$this->logo['w']} /Height {$this->logo['h']} /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ".strlen($alpha)." >>\nstream\n$alpha\nendstream");
            }
            $rgb = (string) gzcompress($this->logo['rgb'], 9);
            $im = $add("<< /Type /XObject /Subtype /Image /Width {$this->logo['w']} /Height {$this->logo['h']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode".($smask ? " /SMask $smask 0 R" : '').' /Length '.strlen($rgb)." >>\nstream\n$rgb\nendstream");
            $xobj = " /XObject << /Im1 $im 0 R >>";
        }
        $kids = [];
        foreach ($this->pages as $i => $content) {
            $content .= $this->chrome($i + 1, $total);
            $stream = (string) gzcompress($content, 6);
            $cid = $add('<< /Filter /FlateDecode /Length '.strlen($stream)." >>\nstream\n$stream\nendstream");
            $kids[] = $add("<< /Type /Page /Parent $pagesId 0 R /MediaBox [0 0 ".self::W.' '.self::H."] /Resources << /Font << /F1 $f1 0 R /F2 $f2 0 R /F3 $f3 0 R >>$xobj >> /Contents $cid 0 R >>");
        }
        $objects[$pagesId - 1] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($k) => "$k 0 R", $kids)).'] /Count '.count($kids).' >>';
        $objects[$catalogId - 1] = "<< /Type /Catalog /Pages $pagesId 0 R /Lang (pt-BR) >>";
        $info = $add('<< /Title ('.self::esc(Metrics::encode($this->header['title'])).') /Producer (BPM) /Creator (BPM relatorios) /CreationDate (D:'.gmdate('YmdHis').'Z) >>');

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= 'trailer'."\n<< /Size ".(count($objects) + 1)." /Root $catalogId 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";

        return $pdf;
    }

    public function pageCount(): int
    {
        return count($this->pages) + 1;
    }

    // ------------------------------------------------------------ internos

    private function newPage(): void
    {
        if ($this->page !== '' || $this->pages !== []) {
            $this->pages[] = $this->page;
        }
        $this->page = '';
        $this->y = self::TOP;
    }

    private function ensure(float $height): void
    {
        if ($this->y + $height > self::H - self::BOTTOM) {
            $this->newPage();
        }
    }

    /** Cabeçalho e rodapé de cada página (desenhados no fim, quando o total é conhecido). */
    private function chrome(int $n, int $total): string
    {
        $out = '';
        $x = self::M;
        if ($this->logo) {
            $h = 26.0;
            $w = min(140.0, $h * $this->logo['w'] / max(1, $this->logo['h']));
            $out .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n", $w, $h, $x, self::H - 30 - $h);
            $x += $w + 10;
        }
        $saved = $this->page;
        $this->page = '';
        $this->text($x, 44, $this->header['org'], 10, 'F2', [0.17, 0.25, 0.33]);
        $this->text($x, 57, $this->header['title'], 8.5, 'F1', [0.4, 0.46, 0.53]);
        if (! empty($this->header['confidentiality'])) {
            $label = mb_strtoupper((string) $this->header['confidentiality']);
            $wLabel = Metrics::width($label, 7.5, true);
            $this->rect(self::W - self::M - $wLabel - 12, 32, $wLabel + 12, 15, [0.99, 0.93, 0.92]);
            $this->text(self::W - self::M - $wLabel - 6, 43, $label, 7.5, 'F2', [0.65, 0.2, 0.2]);
        }
        $this->line(self::M, 68, self::W - self::M, 68, 0.6, $this->brand);
        $footer = $this->header['footer'];
        $this->line(self::M, self::H - 40, self::W - self::M, self::H - 40, 0.4, [0.85, 0.88, 0.91]);
        foreach (array_slice(Metrics::wrap($footer, 7, self::W - 2 * self::M - 80), 0, 2) as $i => $line) {
            $this->text(self::M, self::H - 29 + $i * 9, $line, 7, 'F1', [0.45, 0.5, 0.56]);
        }
        $pageLabel = "Página $n de $total";
        $this->text(self::W - self::M - Metrics::width($pageLabel, 7.5), self::H - 29, $pageLabel, 7.5, 'F2', [0.35, 0.42, 0.5]);
        $out .= $this->page;
        $this->page = $saved;

        return $out;
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    private function text(float $x, float $yTop, string $text, float $size, string $font, array $rgb): void
    {
        $this->page .= sprintf("BT %.3F %.3F %.3F rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $rgb[0], $rgb[1], $rgb[2], $font, $size, $x, self::H - $yTop, self::esc(Metrics::encode($text)));
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    private function line(float $x1, float $y1, float $x2, float $y2, float $width, array $rgb): void
    {
        $this->page .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $rgb[0], $rgb[1], $rgb[2], $width, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    /** @param array{0:float,1:float,2:float} $rgb */
    private function rect(float $x, float $yTop, float $w, float $h, array $rgb): void
    {
        $this->page .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, self::H - $yTop - $h, $w, $h);
    }

    private static function esc(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
    }

    /** @return array{0:float,1:float,2:float} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [0.03, 0.42, 0.47];
        }

        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }

    /** @return array{rgb:string, alpha:?string, w:int, h:int}|null */
    private static function decodeImage(string $bytes): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return null;
        }
        // reduz para no máximo 400 px de largura (logo de cabeçalho)
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w > 400) {
            $scaled = imagescale($img, 400, (int) round($h * 400 / $w));
            if ($scaled) {
                imagedestroy($img);
                $img = $scaled;
                $w = imagesx($img);
                $h = imagesy($img);
            }
        }
        $rgb = '';
        $alpha = '';
        $hasAlpha = false;
        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $c = imagecolorsforindex($img, imagecolorat($img, $px, $py));
                $rgb .= chr($c['red']).chr($c['green']).chr($c['blue']);
                $a = 255 - (int) round($c['alpha'] * 255 / 127);
                $hasAlpha = $hasAlpha || $a < 255;
                $alpha .= chr($a);
            }
        }
        imagedestroy($img);

        return ['rgb' => $rgb, 'alpha' => $hasAlpha ? $alpha : null, 'w' => $w, 'h' => $h];
    }
}
