<?php

namespace App\Backup;

use Generator;

/**
 * Formato tar (ustar) em fluxo: os arquivos da plataforma vão do storage para o backup sem cópia intermediária em disco.
 * O PharData exigiria o arquivo inteiro antes de comprimir.
 */
final class TarStream
{
    private const BLOCK = 512;

    private const MAX_BYTES = 8589934591; // 11 dígitos octais

    /**
     * @param  resource  $out
     * @param  resource  $in  conteúdo, com exatamente $size bytes
     */
    public static function write($out, string $name, int $size, int $mtime, $in): void
    {
        if ($size > self::MAX_BYTES) {
            throw new BackupFailure('file_too_large', "Arquivo grande demais para o pacote: $name.");
        }
        fwrite($out, self::header($name, $size, $mtime));
        $copied = stream_copy_to_stream($in, $out, $size);
        if ($copied !== $size) {
            throw new BackupFailure('file_read_failed', "Leitura incompleta de $name.");
        }
        $pad = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
        if ($pad > 0) {
            fwrite($out, str_repeat("\0", $pad));
        }
    }

    /** @param resource $out */
    public static function finish($out): void
    {
        fwrite($out, str_repeat("\0", self::BLOCK * 2));
    }

    /**
     * @param  resource  $in
     * @return Generator<int, array{name:string, size:int, mtime:int, stream:resource}> $stream traz o conteúdo da entrada atual
     */
    public static function read($in): Generator
    {
        while (($header = self::readExactly($in, self::BLOCK)) !== null) {
            if (trim($header, "\0") === '') {
                return;
            }
            if (! self::validChecksum($header)) {
                throw new BackupFailure('archive_corrupted', 'O pacote de arquivos está corrompido.');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $mtime = (int) octdec(trim(substr($header, 136, 12), "\0 "));
            $tmp = fopen('php://temp/maxmemory:'.(4 * 1024 * 1024), 'w+b');
            if ($tmp === false) {
                throw new BackupFailure('file_read_failed', 'Sem memória temporária para ler o pacote.');
            }
            $left = $size;
            while ($left > 0) {
                $chunk = self::readExactly($in, min($left, 1024 * 1024));
                if ($chunk === null) {
                    throw new BackupFailure('archive_corrupted', 'O pacote de arquivos terminou antes do esperado.');
                }
                fwrite($tmp, $chunk);
                $left -= strlen($chunk);
            }
            rewind($tmp);
            $pad = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
            if ($pad > 0) {
                self::readExactly($in, $pad);
            }
            yield ['name' => $prefix !== '' ? "$prefix/$name" : $name, 'size' => $size, 'mtime' => $mtime, 'stream' => $tmp];
            fclose($tmp);
        }
    }

    private static function header(string $name, int $size, int $mtime): string
    {
        [$prefix, $short] = self::split($name);
        $header = str_pad($short, 100, "\0")
            .sprintf('%07o', 0640)."\0".sprintf('%07o', 0)."\0".sprintf('%07o', 0)."\0"
            .sprintf('%011o', $size)."\0".sprintf('%011o', $mtime)."\0"
            .'        '.'0'.str_repeat("\0", 100)
            ."ustar\0".'00'.str_repeat("\0", 32).str_repeat("\0", 32).str_repeat("\0", 8).str_repeat("\0", 8)
            .str_pad($prefix, 155, "\0").str_repeat("\0", 12);
        $sum = array_sum(array_map('ord', str_split($header)));

        return substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
    }

    /** @return array{0:string,1:string} prefixo e nome: o ustar guarda até 155 + 100 caracteres, partidos em uma barra */
    private static function split(string $name): array
    {
        if (strlen($name) <= 100) {
            return ['', $name];
        }
        $cut = strrpos(substr($name, 0, 156), '/');
        if ($cut === false || strlen($name) - $cut - 1 > 100) {
            throw new BackupFailure('file_name_too_long', "Nome de arquivo longo demais para o pacote: $name.");
        }

        return [substr($name, 0, $cut), substr($name, $cut + 1)];
    }

    private static function validChecksum(string $header): bool
    {
        $stored = (int) octdec(trim(substr($header, 148, 8), "\0 "));
        $blank = substr_replace($header, '        ', 148, 8);

        return array_sum(array_map('ord', str_split($blank))) === $stored;
    }

    /** @param resource $in */
    private static function readExactly($in, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($in, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                return $data === '' ? null : throw new BackupFailure('archive_corrupted', 'O pacote de arquivos terminou antes do esperado.');
            }
            $data .= $chunk;
        }

        return $data;
    }
}
