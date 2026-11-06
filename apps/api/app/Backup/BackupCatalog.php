<?php

namespace App\Backup;

use App\Models\BackupCatalogEntry;
use App\Models\BackupJob;
use App\Settings\SettingsRepository;
use App\Settings\Types\BackupSettings;
use App\Storage\Azure\AzureBlobException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Catálogo dos backups: cache dos manifestos. Depois de uma restauração o banco volta no tempo e num servidor novo ele nem
 * existe, então o catálogo é sempre refeito a partir do disco e do Azure. Também aplica a retenção.
 */
class BackupCatalog
{
    public function __construct(private readonly BackupStorage $storage, private readonly SettingsRepository $settings) {}

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>|null  $verification
     */
    public function register(array $manifest, string $raw, bool $local, bool $azure, ?array $verification = null): BackupCatalogEntry
    {
        $origin = (string) $manifest['origin'];

        return BackupCatalogEntry::updateOrCreate(['id' => $manifest['id']], [
            'scope' => $manifest['scope'], 'origin' => $origin, 'schedule_id' => $manifest['scheduleId'] ?? null,
            'bytes' => (int) collect($manifest['parts'])->sum('bytes'), 'in_local' => $local, 'in_azure' => $azure, 'manifest' => $manifest,
            'manifest_sha256' => hash('sha256', $raw), 'key_fingerprint' => (string) $manifest['encryption']['fingerprint'], 'backup_at' => $manifest['createdAt'],
            'keep_until' => $origin === BackupCatalogEntry::PRE_RESTORE ? now()->addDays((int) config('bpm.backup.pre_restore_days')) : null,
            'verified_at' => $verification ? now() : null, 'verification' => $verification,
        ]);
    }

    /** @return array{added:int, removed:int, total:int} */
    public function sync(): array
    {
        $found = $this->localManifests();
        if ($this->storage->azureEnabled()) {
            foreach ($this->azureManifests() as $id => $raw) {
                $found[$id] = ['raw' => $found[$id]['raw'] ?? $raw, 'local' => $found[$id]['local'] ?? false, 'azure' => true];
            }
        }
        $added = 0;
        foreach ($found as $id => $item) {
            $manifest = json_decode($item['raw'], true);
            if (! is_array($manifest) || ($manifest['id'] ?? null) !== $id || ($manifest['organizationKey'] ?? null) !== BackupPaths::organization()) {
                continue;
            }
            $existing = BackupCatalogEntry::find($id);
            if ($existing) {
                // o hash não muda: é ele que revela um manifesto trocado depois de conhecido
                $existing->update(['in_local' => $item['local'], 'in_azure' => $item['azure']]);

                continue;
            }
            $this->register($manifest, $item['raw'], $item['local'], $item['azure'], $manifest['verification'] ?? null);
            $added++;
        }
        // com o envio desligado o Azure não foi lido, então o que está só lá continua no catálogo
        $gone = BackupCatalogEntry::whereNotIn('id', array_keys($found));
        $this->storage->azureEnabled() || $gone->where('in_azure', false);
        $removed = $gone->delete();

        return ['added' => $added, 'removed' => $removed, 'total' => count($found)];
    }

    /** Aplica a retenção. O backup mais recente nunca sai, e falha ao apagar um item não interrompe os demais. */
    public function prune(): void
    {
        $cfg = $this->settings->value(app(BackupSettings::class));
        $latest = BackupCatalogEntry::orderByDesc('backup_at')->value('id');
        foreach ((array) $cfg['schedules'] as $schedule) {
            $entries = BackupCatalogEntry::where('schedule_id', $schedule['id'])->orderByDesc('backup_at')->get();
            foreach ($entries->slice((int) $schedule['keep']) as $entry) {
                $entry->id === $latest || $this->deleteQuietly($entry);
            }
        }
        foreach (BackupCatalogEntry::where('origin', BackupCatalogEntry::PRE_RESTORE)->where('keep_until', '<', now())->get() as $entry) {
            $entry->id === $latest || $this->deleteQuietly($entry);
        }
        // no servidor fica um número menor de cópias, mas nunca a única cópia de um backup
        foreach (BackupCatalogEntry::where('in_local', true)->where('in_azure', true)->orderByDesc('backup_at')->offset((int) $cfg['localCopies'])->limit(1000)->get() as $entry) {
            File::deleteDirectory($this->storage->local(BackupPaths::dir($entry->id)));
            $entry->update(['in_local' => false]);
        }
        $this->cleanImages();
        BackupJob::whereIn('status', [BackupJob::SUCCEEDED, BackupJob::FAILED])->where('finished_at', '<', now()->subDays(180))->whereNull('schedule_id')->delete();
    }

    public function delete(BackupCatalogEntry $entry): void
    {
        if ($entry->in_azure) {
            foreach ($this->storage->azureList(BackupPaths::dir($entry->id).'/') as $blob) {
                $this->storage->azureDelete($blob['name']);
            }
        }
        File::deleteDirectory($this->storage->local(BackupPaths::dir($entry->id)));
        $entry->delete();
    }

    /** Imagem só sai quando nenhum manifesto a cita. */
    public function cleanImages(): void
    {
        $used = BackupCatalogEntry::where('scope', BackupSettings::FULL)->get()->flatMap(fn ($e) => collect($e->manifest['images'] ?? [])->pluck('file'))->flip();
        foreach (glob($this->storage->local(BackupPaths::imagesDir()).'/*.age') ?: [] as $file) {
            $used->has(BackupPaths::imagesDir().'/'.basename($file)) || @unlink($file);
        }
        if (! $this->storage->azureEnabled()) {
            return;
        }
        try {
            foreach ($this->storage->azureList(BackupPaths::imagesDir().'/') as $blob) {
                $used->has($blob['name']) || $this->storage->azureDelete($blob['name']);
            }
        } catch (AzureBlobException $e) {
            Log::warning('Backup: imagens antigas não removidas do Azure', ['code' => $e->errorCode]);
        }
    }

    private function deleteQuietly(BackupCatalogEntry $entry): void
    {
        try {
            $this->delete($entry);
        } catch (AzureBlobException $e) {
            Log::warning('Backup: retenção não apagou um backup do Azure', ['backup' => $entry->id, 'code' => $e->errorCode]);
        }
    }

    /** @return array<string, array{raw:string, local:bool, azure:bool}> */
    private function localManifests(): array
    {
        $found = [];
        foreach (glob($this->storage->local(BackupPaths::organization().'/*/*/*/'.BackupPaths::MANIFEST)) ?: [] as $file) {
            $found[basename(dirname($file))] = ['raw' => (string) file_get_contents($file), 'local' => true, 'azure' => false];
        }

        return $found;
    }

    /** @return array<string,string> id => manifesto */
    private function azureManifests(): array
    {
        $found = [];
        foreach ($this->storage->azureList(BackupPaths::organization().'/') as $blob) {
            if (basename($blob['name']) === BackupPaths::MANIFEST) {
                $found[basename(dirname($blob['name']))] = $this->storage->azureGet($blob['name']);
            }
        }

        return $found;
    }
}
