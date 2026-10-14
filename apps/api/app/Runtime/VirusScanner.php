<?php

namespace App\Runtime;

use Throwable;

/**
 * Antivírus de anexos. Usa ClamAV (clamd INSTREAM) quando BPM_CLAMAV_HOST está configurado;
 * caso contrário marca "not_scanned" (aceitável apenas em dev/POC).
 */
class VirusScanner
{
    /** @return 'clean'|'infected'|'not_scanned' */
    public function scan(string $path): string
    {
        $host = config('bpm.attachments.clamav.host');
        if (! $host) {
            return 'not_scanned';
        }
        try {
            $socket = @fsockopen($host, (int) config('bpm.attachments.clamav.port', 3310), $errno, $errstr, 5);
            if (! $socket) {
                return 'not_scanned';
            }
            stream_set_timeout($socket, 30);
            fwrite($socket, "zINSTREAM\0");
            $handle = fopen($path, 'rb');
            while (! feof($handle)) {
                $chunk = fread($handle, 8192);
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fclose($handle);
            fwrite($socket, pack('N', 0));
            $reply = trim((string) fgets($socket));
            fclose($socket);

            return str_contains($reply, 'FOUND') ? 'infected' : (str_ends_with($reply, 'OK') ? 'clean' : 'not_scanned');
        } catch (Throwable) {
            return 'not_scanned';
        }
    }
}
