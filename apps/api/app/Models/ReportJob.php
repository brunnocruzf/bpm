<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Job de geração de relatório e artefato privado (com expiração).
 *
 * @property string $id
 * @property string $report_type
 * @property string|null $template_id
 * @property int|null $template_version
 * @property string|null $process_instance_id
 * @property array<string,mixed> $parameters
 * @property string $format
 * @property string $status
 * @property int $progress
 * @property int $attempts
 * @property string|null $renderer
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $artifact_disk
 * @property string|null $artifact_key
 * @property string|null $artifact_name
 * @property string|null $artifact_type
 * @property int|null $artifact_size
 * @property string|null $artifact_sha256
 * @property int|null $row_count
 * @property string $requested_by
 * @property string $requested_by_name
 * @property string|null $idempotency_key
 * @property string|null $correlation_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
class ReportJob extends Model
{
    use HasUuids;

    public const QUEUED = 'queued';

    public const RENDERING = 'rendering';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $table = 'report_job';

    protected $fillable = [
        'report_type', 'template_id', 'template_version', 'process_instance_id', 'parameters', 'format', 'status', 'progress', 'attempts',
        'renderer', 'error_code', 'error_message', 'artifact_disk', 'artifact_key', 'artifact_name', 'artifact_type', 'artifact_size',
        'artifact_sha256', 'row_count', 'requested_by', 'requested_by_name', 'idempotency_key', 'correlation_id', 'started_at', 'finished_at', 'expires_at',
    ];

    protected $attributes = ['parameters' => '{}', 'status' => self::QUEUED, 'progress' => 0, 'attempts' => 0];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime', 'progress' => 'integer', 'attempts' => 'integer'];
    }

    /** @return BelongsTo<ReportTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'template_id');
    }
}
