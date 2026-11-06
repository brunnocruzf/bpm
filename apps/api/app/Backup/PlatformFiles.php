<?php

namespace App\Backup;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

/**
 * Arquivos do armazenamento da plataforma (anexos, relatórios, auditoria arquivada, logos e fotos), lidos pela API do disco
 * e não pelo volume: o formato interno do RustFS muda, e a cópia direta de um volume em uso não é consistente. Anexos em
 * destinos Azure ficam fora: já estão fora do servidor.
 */
class PlatformFiles
{
    /** @return list<string> */
    public function disks(): array
    {
        $names = [config('bpm.attachments.disk'), config('bpm.reports.disk'), config('bpm.audit.archive_disk'), config('bpm.directory.photos.disk')];

        return array_values(array_unique(array_filter(array_map('strval', $names))));
    }

    /**
     * @param  resource  $out
     * @return array{files:int, bytes:int}
     */
    public function archive($out): array
    {
        $files = 0;
        $bytes = 0;
        foreach ($this->disks() as $name) {
            $disk = $this->disk($name);
            foreach ($disk->getDriver()->listContents('', true) as $item) {
                if (! $item instanceof FileAttributes) {
                    continue;
                }
                $size = $item->fileSize() ?? (int) $disk->size($item->path());
                $in = $disk->readStream($item->path());
                if (! is_resource($in)) {
                    throw new BackupFailure('file_read_failed', 'Não foi possível ler um arquivo do armazenamento.');
                }
                TarStream::write($out, $name.'/'.$item->path(), $size, $item->lastModified() ?? time(), $in);
                fclose($in);
                $files++;
                $bytes += $size;
            }
        }
        TarStream::finish($out);

        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * Grava de volta sem apagar nada: arquivo mais novo que o backup vira só um arquivo a mais, sem referência no banco.
     *
     * @param  resource  $in
     * @return array{files:int, skipped:int}
     */
    public function restore($in): array
    {
        $known = array_flip($this->disks());
        $files = 0;
        $skipped = 0;
        foreach (TarStream::read($in) as $entry) {
            [$name, $path] = array_pad(explode('/', $entry['name'], 2), 2, '');
            if (! isset($known[$name]) || $path === '' || str_contains($path, '..')) {
                $skipped++;

                continue;
            }
            $disk = $this->disk($name);
            if ($disk->exists($path) && $disk->size($path) === $entry['size']) {
                $skipped++;

                continue;
            }
            $disk->writeStream($path, $entry['stream']);
            $files++;
        }

        return ['files' => $files, 'skipped' => $skipped];
    }

    private function disk(string $name): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($name);

        return $disk;
    }
}
