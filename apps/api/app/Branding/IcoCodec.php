<?php

namespace App\Branding;

use GdImage;
use InvalidArgumentException;

/**
 * Leitura estrita e regravação de favicons .ico (sem dependências).
 *
 * decode(): valida o contêiner (cabeçalho, diretório, limites de offset/tamanho) e decodifica cada
 * imagem — PNG embutido (via GD) ou DIB/BMP sem compressão de 1, 4, 8, 24 ou 32 bits com máscara
 * AND. Qualquer coisa fora disso é recusada.
 * encode(): gera um ICO NOVO apenas com frames PNG recodificados (16, 32, 48 e o tamanho de origem
 * até 256 px) — nada do arquivo original (metadados, bytes extras) é preservado.
 */
final class IcoCodec
{
    private const MAX_FRAMES = 16;

    private const MAX_SIDE = 256;

    /**
     * @return list<array{image: GdImage, width: int, height: int}>
     *
     * @throws InvalidArgumentException
     */
    public static function decode(string $bytes): array
    {
        $len = strlen($bytes);
        if ($len < 6 + 16) {
            throw new InvalidArgumentException('Arquivo ICO truncado.');
        }
        $head = unpack('vreserved/vtype/vcount', substr($bytes, 0, 6));
        if (! $head || $head['reserved'] !== 0 || $head['type'] !== 1 || $head['count'] < 1 || $head['count'] > self::MAX_FRAMES) {
            throw new InvalidArgumentException('Cabeçalho ICO inválido.');
        }
        if ($len < 6 + 16 * $head['count']) {
            throw new InvalidArgumentException('Diretório ICO truncado.');
        }
        $frames = [];
        for ($i = 0; $i < $head['count']; $i++) {
            $e = unpack('Cw/Ch/Ccolors/Creserved/vplanes/vbpp/Vsize/Voffset', substr($bytes, 6 + 16 * $i, 16));
            if (! $e) {
                throw new InvalidArgumentException('Entrada ICO inválida.');
            }
            $w = $e['w'] === 0 ? 256 : $e['w'];
            $h = $e['h'] === 0 ? 256 : $e['h'];
            if ($e['size'] < 8 || $e['offset'] < 6 + 16 * $head['count'] || $len < $e['offset'] + $e['size']) {
                throw new InvalidArgumentException('Imagem ICO fora dos limites do arquivo.');
            }
            $payload = substr($bytes, $e['offset'], $e['size']);
            $image = str_starts_with($payload, "\x89PNG\r\n\x1a\n") ? self::decodePng($payload) : self::decodeDib($payload, $w, $h);
            $frames[] = ['image' => $image, 'width' => imagesx($image), 'height' => imagesy($image)];
        }

        return $frames;
    }

    /** @param list<array{image: GdImage, width: int, height: int}> $frames */
    public static function largest(array $frames): array
    {
        usort($frames, fn ($a, $b) => $b['width'] * $b['height'] <=> $a['width'] * $a['height']);

        return $frames[0];
    }

    /** Gera um ICO limpo (frames PNG) a partir da imagem de origem quadrada. */
    public static function encode(GdImage $source): string
    {
        $side = min(imagesx($source), imagesy($source), self::MAX_SIDE);
        $sizes = array_values(array_unique(array_filter([16, 32, 48, $side], fn ($s) => $s <= $side)));
        $payloads = [];
        foreach ($sizes as $s) {
            $frame = imagecreatetruecolor($s, $s);
            imagealphablending($frame, false);
            imagesavealpha($frame, true);
            imagefill($frame, 0, 0, (int) imagecolorallocatealpha($frame, 0, 0, 0, 127));
            imagecopyresampled($frame, $source, 0, 0, 0, 0, $s, $s, imagesx($source), imagesy($source));
            ob_start();
            imagepng($frame, null, 9);
            $payloads[$s] = (string) ob_get_clean();
            imagedestroy($frame);
        }
        $out = pack('vvv', 0, 1, count($payloads));
        $offset = 6 + 16 * count($payloads);
        $data = '';
        foreach ($payloads as $s => $png) {
            $out .= pack('CCCCvvVV', $s >= 256 ? 0 : $s, $s >= 256 ? 0 : $s, 0, 0, 1, 32, strlen($png), $offset);
            $offset += strlen($png);
            $data .= $png;
        }

        return $out.$data;
    }

    private static function decodePng(string $payload): GdImage
    {
        $img = @imagecreatefromstring($payload);
        if (! $img instanceof GdImage || imagesx($img) > self::MAX_SIDE || imagesy($img) > self::MAX_SIDE) {
            throw new InvalidArgumentException('Imagem PNG do ICO inválida.');
        }

        return $img;
    }

    /** DIB (BITMAPINFOHEADER) sem compressão, altura dupla (XOR + máscara AND). */
    private static function decodeDib(string $p, int $entryW, int $entryH): GdImage
    {
        $hdr = unpack('VhdrSize/lwidth/lheight/vplanes/vbpp/Vcompression/VimageSize/lxppm/lyppm/VcolorsUsed', substr($p, 0, 36));
        if (! $hdr || ! in_array($hdr['hdrSize'], [40, 108, 124], true) || $hdr['compression'] !== 0 || $hdr['planes'] !== 1) {
            throw new InvalidArgumentException('Bitmap do ICO não suportado (somente BMP sem compressão).');
        }
        $w = $hdr['width'];
        $h = intdiv(abs($hdr['height']), 2);
        $bpp = $hdr['bpp'];
        if ($w < 1 || $h < 1 || $w > self::MAX_SIDE || $h > self::MAX_SIDE || ! in_array($bpp, [1, 4, 8, 24, 32], true)) {
            throw new InvalidArgumentException('Dimensões ou profundidade de cor do ICO inválidas.');
        }
        if (($entryW !== $w && $entryW !== 256) || ($entryH !== $h && $entryH !== 256)) {
            throw new InvalidArgumentException('Dimensões do diretório ICO não conferem com a imagem.');
        }
        $bottomUp = $hdr['height'] > 0;
        $pos = $hdr['hdrSize'];
        $palette = [];
        if ($bpp <= 8) {
            $n = $hdr['colorsUsed'] ?: 1 << $bpp;
            if ($n > 256 || $pos + 4 * $n > strlen($p)) {
                throw new InvalidArgumentException('Paleta do ICO inválida.');
            }
            for ($i = 0; $i < $n; $i++) {
                $palette[] = [ord($p[$pos + 4 * $i + 2]), ord($p[$pos + 4 * $i + 1]), ord($p[$pos + 4 * $i])];
            }
            $pos += 4 * $n;
        }
        $xorRow = intdiv($bpp * $w + 31, 32) * 4;
        $andRow = intdiv($w + 31, 32) * 4;
        $andPos = $pos + $xorRow * $h;
        $hasMask = $andPos + $andRow * $h <= strlen($p);
        if ($pos + $xorRow * $h > strlen($p)) {
            throw new InvalidArgumentException('Bitmap do ICO truncado.');
        }

        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $alphaSeen = false;
        $pixels = [];
        for ($y = 0; $y < $h; $y++) {
            $row = $pos + $xorRow * ($bottomUp ? $h - 1 - $y : $y);
            for ($x = 0; $x < $w; $x++) {
                [$r, $g, $b, $a] = match ($bpp) {
                    32 => [ord($p[$row + 4 * $x + 2]), ord($p[$row + 4 * $x + 1]), ord($p[$row + 4 * $x]), ord($p[$row + 4 * $x + 3])],
                    24 => [ord($p[$row + 3 * $x + 2]), ord($p[$row + 3 * $x + 1]), ord($p[$row + 3 * $x]), 255],
                    default => [...self::paletteColor($palette, self::index($p, $row, $x, $bpp)), 255],
                };
                $alphaSeen = $alphaSeen || ($bpp === 32 && $a > 0);
                $pixels[$y][$x] = [$r, $g, $b, $a];
            }
        }
        for ($y = 0; $y < $h; $y++) {
            $maskRow = $andPos + $andRow * ($bottomUp ? $h - 1 - $y : $y);
            for ($x = 0; $x < $w; $x++) {
                [$r, $g, $b, $a] = $pixels[$y][$x];
                if ($bpp !== 32 || ! $alphaSeen) {
                    // máscara AND: bit 1 = transparente
                    $transparent = $hasMask && (ord($p[$maskRow + ($x >> 3)]) >> (7 - ($x & 7)) & 1) === 1;
                    $a = $transparent ? 0 : 255;
                }
                imagesetpixel($img, $x, $y, (int) imagecolorallocatealpha($img, $r, $g, $b, 127 - ($a >> 1)));
            }
        }

        return $img;
    }

    private static function index(string $p, int $row, int $x, int $bpp): int
    {
        $bit = $x * $bpp;
        $byte = ord($p[$row + ($bit >> 3)]);

        return match ($bpp) {
            8 => $byte,
            4 => ($bit & 7) === 0 ? $byte >> 4 : $byte & 0x0F,
            default => ($byte >> (7 - ($bit & 7))) & 1,
        };
    }

    /**
     * @param  list<array{0:int,1:int,2:int}>  $palette
     * @return array{0:int,1:int,2:int}
     */
    private static function paletteColor(array $palette, int $i): array
    {
        return $palette[$i] ?? throw new InvalidArgumentException('Índice de paleta inválido no ICO.');
    }
}
