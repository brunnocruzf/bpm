<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pedido ao agente de backup e histórico da execução (ADR-0021).
 *
 * @property string $id
 * @property string $kind
 * @property string $status
 * @property array<string,mixed>|null $params
 * @property string|null $sealed_secret
 * @property string|null $schedule_id
 * @property Carbon|null $scheduled_for
 * @property string|null $backup_id
 * @property string|null $stage
 * @property int $percent
 * @property string|null $error_code
 * @property string|null $error_detail
 * @property string|null $requested_by
 * @property string|null $requested_by_name
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
class BackupJob extends Model
{
    use HasUuids;

    public const BACKUP = 'backup';

    public const RESTORE = 'restore';

    public const RESTORE_TEST = 'restore_test';

    public const DELETE = 'delete';

    public const COPY_AZURE = 'copy_azure';

    public const CATALOG_SYNC = 'catalog_sync';

    public const UNDO_RESTORE = 'undo_restore';

    public const RELEASE = 'release';

    public const KINDS = [self::BACKUP, self::RESTORE, self::RESTORE_TEST, self::DELETE, self::COPY_AZURE, self::CATALOG_SYNC, self::UNDO_RESTORE, self::RELEASE];

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $table = 'backup_job';

    protected $fillable = [
        'id', 'kind', 'status', 'params', 'sealed_secret', 'schedule_id', 'scheduled_for', 'backup_id', 'stage', 'percent', 'error_code', 'error_detail',
        'requested_by', 'requested_by_name', 'started_at', 'finished_at',
    ];

    protected $hidden = ['sealed_secret'];

    protected $attributes = ['status' => self::PENDING, 'percent' => 0];

    protected function casts(): array
    {
        return ['params' => 'array', 'scheduled_for' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'percent' => 'integer'];
    }

    /** @return array<string,mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'status' => $this->status, 'backupId' => $this->backup_id, 'stage' => $this->stage, 'percent' => $this->percent,
            'scope' => $this->params['scope'] ?? null, 'errorCode' => $this->error_code, 'errorDetail' => $this->error_detail, 'requestedBy' => $this->requested_by_name,
            'createdAt' => $this->created_at?->toIso8601String(), 'startedAt' => $this->started_at?->toIso8601String(), 'finishedAt' => $this->finished_at?->toIso8601String(),
        ];
    }
}
