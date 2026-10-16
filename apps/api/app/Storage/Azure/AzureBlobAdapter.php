<?php

namespace App\Storage\Azure;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Throwable;

/**
 * Adaptador Flysystem sobre o AzureBlobClient para os anexos. Contêiner sempre privado: visibilidade e diretórios
 * não existem aqui (o acesso é pela API, com URL assinada do Laravel). O prefixo é transparente para quem chama.
 */
final class AzureBlobAdapter implements FilesystemAdapter
{
    private readonly PathPrefixer $prefixer;

    public function __construct(private readonly AzureBlobClient $client, string $prefix = '')
    {
        $this->prefixer = new PathPrefixer($prefix);
    }

    public function fileExists(string $path): bool
    {
        return $this->client->head($this->prefixer->prefixPath($path)) !== null;
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->client->put($this->prefixer->prefixPath($path), $contents, $config->get('mimetype'));
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $this->reason($e), $e);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $data = stream_get_contents($contents);
        if ($data === false) {
            throw UnableToWriteFile::atLocation($path, 'Não foi possível ler o fluxo de origem.');
        }
        $this->write($path, $data, $config);
    }

    public function read(string $path): string
    {
        try {
            return $this->client->get($this->prefixer->prefixPath($path));
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $this->reason($e), $e);
        }
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        try {
            $this->client->delete($this->prefixer->prefixPath($path));
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $this->reason($e), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        throw UnableToDeleteDirectory::atLocation($path, 'Diretórios não são suportados neste armazenamento.');
    }

    public function createDirectory(string $path, Config $config): void
    {
        // diretórios são virtuais no Blob Storage: nada a criar
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'O contêiner é privado; visibilidade por arquivo não existe.');
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'O contêiner é privado; visibilidade por arquivo não existe.');
    }

    public function mimeType(string $path): FileAttributes
    {
        $info = $this->info($path, 'mime_type');

        return new FileAttributes($path, null, null, null, $info['contentType'] ?? 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        $info = $this->info($path, 'last_modified');

        return new FileAttributes($path, null, null, $info['lastModified']);
    }

    public function fileSize(string $path): FileAttributes
    {
        $info = $this->info($path, 'file_size');

        return new FileAttributes($path, $info['size']);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        throw UnableToListContents::atLocation($path, $deep, new \RuntimeException('Listagem não é suportada neste armazenamento.'));
    }

    public function move(string $source, string $destination, Config $config): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
    }

    /** @return array{size:int, contentType:?string, lastModified:?int} */
    private function info(string $path, string $attribute): array
    {
        try {
            $info = $this->client->head($this->prefixer->prefixPath($path));
        } catch (Throwable $e) {
            throw new UnableToRetrieveMetadata($this->reason($e), 0, $e);
        }
        if ($info === null) {
            throw match ($attribute) {
                'mime_type' => UnableToRetrieveMetadata::mimeType($path, 'Arquivo não encontrado.'),
                'last_modified' => UnableToRetrieveMetadata::lastModified($path, 'Arquivo não encontrado.'),
                default => UnableToRetrieveMetadata::fileSize($path, 'Arquivo não encontrado.'),
            };
        }

        return $info;
    }

    private function reason(Throwable $e): string
    {
        return $e instanceof AzureBlobException ? $e->errorCode : 'storage_error';
    }
}
