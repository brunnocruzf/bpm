<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Destino de armazenamento de anexos ativado (Azure Blob). `config` não contém segredos; a chave fica no SecretStore.
 *
 * @property string $id
 * @property string $disk
 * @property string $provider
 * @property array<string,mixed> $config
 * @property string|null $credential_ref
 * @property string $status
 * @property int|null $setting_version
 * @property string|null $activated_by
 * @property Carbon|null $activated_at
 * @property Carbon|null $retired_at
 * @property Carbon|null $updated_at
 */
class StorageTarget extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const RETIRED = 'retired';

    protected $table = 'storage_target';

    protected $fillable = ['disk', 'provider', 'config', 'credential_ref', 'status', 'setting_version', 'activated_by', 'activated_at', 'retired_at'];

    protected $hidden = ['credential_ref'];

    protected function casts(): array
    {
        return ['config' => 'array', 'activated_at' => 'datetime', 'retired_at' => 'datetime', 'setting_version' => 'integer'];
    }
}
