<?php

namespace App\Storage;

use App\Models\StorageTarget;
use App\Models\User;
use App\Settings\SecretStore;
use App\Settings\Types\AttachmentStorageSettings;
use Illuminate\Support\Facades\DB;

/**
 * Registro de destinos de anexos. Ativar grava (ou atualiza, em caso de rotação da chave) o destino pela identidade
 * endpoint|conta|contêiner|prefixo, com cópia própria do segredo, e aposenta o anterior — que continua legível para
 * os anexos que já estão nele. Desativar aposenta o ativo e os novos anexos voltam ao disco do ambiente.
 */
class StorageTargetService
{
    public function __construct(private readonly SecretStore $secrets) {}

    /** @param array<string,mixed> $cfg valor salvo de attachments.storage */
    public function activate(array $cfg, int $settingVersion, User $by): StorageTarget
    {
        $a = (array) $cfg['azure'];
        $disk = AttachmentStorageSettings::diskName($cfg);
        $ref = (string) $a['credentialRef'];
        $config = [
            'endpoint' => AttachmentStorageSettings::endpoint($cfg), 'endpointMode' => $a['endpointMode'], 'cloud' => $a['cloud'],
            'accountName' => AttachmentStorageSettings::account($cfg), 'container' => $a['container'], 'pathPrefix' => trim((string) ($a['pathPrefix'] ?? ''), '/'),
        ];

        $target = DB::transaction(function () use ($disk, $ref, $config, $settingVersion, $by) {
            $target = StorageTarget::where('disk', $disk)->lockForUpdate()->first() ?? new StorageTarget(['disk' => $disk, 'provider' => 'azure_blob']);
            // db:  → cópia própria (rotação da mesma identidade atualiza o segredo do destino); file: → a referência montada
            $own = str_starts_with($ref, 'db:')
                ? $this->secrets->put('attachments.azure.target_key', (string) $this->secrets->get($ref), $by, $target->credential_ref && str_starts_with($target->credential_ref, 'db:') ? $target->credential_ref : null)
                : $ref;
            StorageTarget::where('status', StorageTarget::ACTIVE)->where('disk', '!=', $disk)->update(['status' => StorageTarget::RETIRED, 'retired_at' => now()]);
            $target->fill(['config' => $config, 'credential_ref' => $own, 'status' => StorageTarget::ACTIVE, 'setting_version' => $settingVersion, 'activated_by' => $by->id, 'activated_at' => now(), 'retired_at' => null])->save();

            return $target;
        });
        AttachmentStorage::forget();

        return $target;
    }

    public function deactivate(): void
    {
        StorageTarget::where('status', StorageTarget::ACTIVE)->update(['status' => StorageTarget::RETIRED, 'retired_at' => now()]);
        AttachmentStorage::forget();
    }

    /** @return list<array<string,mixed>> uso por destino (contagem e tamanho dos anexos), sem segredos */
    public function usage(): array
    {
        $rows = DB::table('attachment')->selectRaw('disk, count(*) as total, coalesce(sum(size), 0) as bytes')->groupBy('disk')->get();
        $targets = StorageTarget::all()->keyBy('disk');

        return $rows->map(function ($r) use ($targets) {
            $t = $targets->get($r->disk);

            return [
                'disk' => $r->disk, 'files' => (int) $r->total, 'bytes' => (int) $r->bytes,
                'label' => $t ? 'Azure Blob — '.($t->config['accountName'] ?? '').'/'.($t->config['container'] ?? '') : (config("filesystems.disks.{$r->disk}") !== null ? 'Plataforma ('.$r->disk.')' : $r->disk),
                'status' => $t ? $t->status : 'platform',
            ];
        })->values()->all();
    }
}
