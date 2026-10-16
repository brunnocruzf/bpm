<?php

namespace App\Storage;

use App\Models\StorageTarget;
use App\Settings\SecretStore;
use App\Storage\Azure\AzureBlobException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Resolve o disco dos anexos: o destino ativo (Azure Blob, ativado em Configurações) ou o disco do ambiente.
 * Cada anexo guarda o nome do disco em que foi gravado; discos `azblob_*` resolvem pelo registro `storage_target`
 * (inclusive destinos aposentados), então nada fica ilegível quando a configuração muda.
 */
class AttachmentStorage
{
    private const CACHE_KEY = 'bpm:attachments-storage:active';

    /** @var array<string, FilesystemAdapter> */
    private array $memo = [];

    public function __construct(private readonly SecretStore $secrets) {}

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Disco para novos anexos. */
    public function currentDisk(): string
    {
        $active = Cache::remember(self::CACHE_KEY, 30, fn () => (string) StorageTarget::where('status', StorageTarget::ACTIVE)->value('disk'));

        return $active !== '' ? $active : (string) config('bpm.attachments.disk');
    }

    public function disk(string $name): FilesystemAdapter
    {
        if (config("filesystems.disks.$name") !== null) {
            /** @var FilesystemAdapter $disk */
            $disk = Storage::disk($name);

            return $disk;
        }
        $target = StorageTarget::where('disk', $name)->first()
            ?? throw new AzureBlobException('storage_target_missing', 'O destino de armazenamento deste arquivo não está mais registrado.');
        $key = $target->id.':'.$target->updated_at?->getTimestamp().':'.($target->credential_ref ?? '');

        return $this->memo[$key] ??= $this->build($target);
    }

    private function build(StorageTarget $target): FilesystemAdapter
    {
        $key = $this->secrets->get($target->credential_ref)
            ?? throw new AzureBlobException('credential_invalid', 'A chave do destino de armazenamento não pôde ser lida.');
        $c = $target->config;

        /** @var FilesystemAdapter $disk */
        $disk = Storage::build([
            'driver' => 'bpm-azure-blob', 'throw' => true, 'report' => false,
            'account' => (string) $c['accountName'], 'key' => $key, 'endpoint' => (string) $c['endpoint'],
            'container' => (string) $c['container'], 'prefix' => (string) ($c['pathPrefix'] ?? ''),
        ]);

        return $disk;
    }
}
