<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Backup conhecido pelo sistema: cache do manifesto, refeito a partir do disco e do Azure (BackupCatalog::sync).
 *
 * @property string $id
 * @property string $scope
 * @property string $origin
 * @property string|null $schedule_id
 * @property int $bytes
 * @property bool $in_local
 * @property bool $in_azure
 * @property array<string,mixed> $manifest
 * @property string $manifest_sha256
 * @property string $key_fingerprint
 * @property Carbon $backup_at
 * @property Carbon|null $keep_until
 * @property Carbon|null $verified_at
 * @property array<string,mixed>|null $verification
 */
class BackupCatalogEntry extends Model
{
    public const MANUAL = 'manual';

    public const SCHEDULE = 'schedule';

    public const PRE_RESTORE = 'pre_restore';

    public $incrementing = false;

    protected $table = 'backup_catalog';

    protected $keyType = 'string';

    protected $fillable = ['id', 'scope', 'origin', 'schedule_id', 'bytes', 'in_local', 'in_azure', 'manifest', 'manifest_sha256', 'key_fingerprint', 'backup_at', 'keep_until', 'verified_at', 'verification'];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer', 'in_local' => 'boolean', 'in_azure' => 'boolean', 'manifest' => 'array', 'verification' => 'array',
            'backup_at' => 'datetime', 'keep_until' => 'datetime', 'verified_at' => 'datetime',
        ];
    }

    /** @return array<string,mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id, 'scope' => $this->scope, 'origin' => $this->origin, 'scheduleId' => $this->schedule_id, 'bytes' => $this->bytes,
            'inLocal' => $this->in_local, 'inAzure' => $this->in_azure, 'keyFingerprint' => $this->key_fingerprint,
            'backupAt' => $this->backup_at->toIso8601String(), 'verifiedAt' => $this->verified_at?->toIso8601String(), 'verification' => $this->verification,
            'keepUntil' => $this->keep_until?->toIso8601String(),
        ];
    }
}
