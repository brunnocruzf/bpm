<?php

namespace App\Backup;

use App\Models\StorageTarget;
use App\Settings\SecretStore;
use App\Settings\SettingsRepository;
use App\Settings\Types\BackupSettings;
use App\Storage\Azure\AzureBlobClient;
use App\Storage\Azure\AzureBlobException;

/**
 * Onde os backups ficam: disco do servidor e, opcionalmente, uma pasta no contêiner do destino ativo de Armazenamento de
 * anexos, relativa ao prefixo dele. Os caminhos relativos são os de BackupPaths nos dois lugares.
 */
class BackupStorage
{
    private ?AzureBlobClient $client = null;

    private ?string $clientKey = null;

    public function __construct(private readonly SecretStore $secrets, private readonly SettingsRepository $settings) {}

    public function root(): string
    {
        return rtrim((string) config('bpm.backup.dir'), '/\\');
    }

    public function local(string $relative): string
    {
        return $this->root().'/'.$relative;
    }

    public function stagingDir(string $name): string
    {
        return $this->ensureDir($this->local('.staging/'.$name));
    }

    public function ensureDir(string $path): string
    {
        if (! is_dir($path)) {
            mkdir($path, 0750, true);
        }

        return $path;
    }

    public function target(): ?StorageTarget
    {
        return StorageTarget::where('status', StorageTarget::ACTIVE)->first();
    }

    /** O envio precisa estar ligado em Configurações e o destino ativo precisa existir. */
    public function azureEnabled(): bool
    {
        return ($this->settings->value(app(BackupSettings::class))['azure']['enabled'] ?? false) && $this->target() !== null;
    }

    public function azureKey(string $relative): string
    {
        $target = $this->requireTarget();
        $folder = (string) $this->settings->value(app(BackupSettings::class))['azure']['folder'];

        return implode('/', array_filter([trim((string) ($target->config['pathPrefix'] ?? ''), '/'), $folder, $relative], fn ($p) => $p !== ''));
    }

    public function upload(string $relative): void
    {
        $this->uploadFrom($this->local($relative), $relative);
    }

    public function uploadFrom(string $file, string $relative, ?int $blockBytes = null): void
    {
        $this->client()->putFile($this->azureKey($relative), $file, $blockBytes ?? $this->blockBytes());
    }

    public function download(string $relative, string $target, ?int $chunkBytes = null): void
    {
        $this->client()->downloadTo($this->azureKey($relative), $target, $chunkBytes ?? $this->blockBytes());
    }

    public function azureHas(string $relative): bool
    {
        return $this->client()->head($this->azureKey($relative)) !== null;
    }

    public function azureGet(string $relative): string
    {
        return $this->client()->get($this->azureKey($relative));
    }

    public function azurePut(string $relative, string $contents, string $contentType = 'application/octet-stream'): void
    {
        $this->client()->put($this->azureKey($relative), $contents, $contentType);
    }

    public function azureDelete(string $relative): void
    {
        $this->client()->delete($this->azureKey($relative));
    }

    /** @return list<array{name:string, size:int}> caminhos relativos à pasta de backups */
    public function azureList(string $prefix): array
    {
        $base = $this->azureKey('');
        $base = $base === '' ? '' : rtrim($base, '/').'/';

        return array_map(fn ($b) => ['name' => substr($b['name'], strlen($base)), 'size' => $b['size']], $this->client()->list($base.$prefix));
    }

    public function blockBytes(): int
    {
        return (int) config('bpm.backup.azure_block_bytes');
    }

    private function requireTarget(): StorageTarget
    {
        return $this->target() ?? throw new BackupFailure('azure_unavailable', 'Nenhum destino Azure está ativo em Armazenamento de anexos.');
    }

    private function client(): AzureBlobClient
    {
        $target = $this->requireTarget();
        $key = $target->id.':'.$target->updated_at?->getTimestamp();
        if ($this->client !== null && $this->clientKey === $key) {
            return $this->client;
        }
        $accountKey = $this->secrets->get($target->credential_ref) ?? throw new AzureBlobException('credential_invalid', 'A chave do destino de armazenamento não pôde ser lida.');
        $c = $target->config;
        $this->clientKey = $key;

        return $this->client = new AzureBlobClient((string) $c['accountName'], $accountKey, (string) $c['endpoint'], (string) $c['container'],
            (string) config('bpm.storage.azure.api_version'), (int) config('bpm.storage.azure.timeout', 30), (int) config('bpm.storage.azure.connect_timeout', 5));
    }
}
