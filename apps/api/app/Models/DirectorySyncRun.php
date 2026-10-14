<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Execução de sincronização/prévia do diretório.
 *
 * @property string $id
 * @property string $mode
 * @property string $trigger
 * @property string $status
 * @property string $transport
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property array<string,int> $totals
 * @property array<int,array<string,mixed>> $errors
 * @property array<string,mixed>|null $plan
 * @property list<array{at:string, ms:int, level:string, step:string, message:string, data?:array<string,mixed>}>|null $log
 * @property string|null $error_code
 * @property string|null $error_message
 * @property bool $cursor_advanced
 * @property string|null $requested_by
 * @property string|null $requested_by_name
 * @property string|null $correlation_id
 * @property Carbon|null $created_at
 */
class DirectorySyncRun extends Model
{
    use HasUuids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    protected $table = 'directory_sync_run';

    protected $fillable = ['mode', 'trigger', 'status', 'transport', 'started_at', 'finished_at', 'duration_ms', 'totals', 'errors', 'plan', 'log', 'error_code', 'error_message', 'cursor_advanced', 'requested_by', 'requested_by_name', 'correlation_id'];

    protected $attributes = ['totals' => '{}', 'errors' => '[]', 'status' => self::QUEUED, 'cursor_advanced' => false];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'totals' => 'array', 'errors' => 'array', 'plan' => 'array', 'log' => 'array', 'cursor_advanced' => 'boolean'];
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }
}
