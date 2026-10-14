<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Submissão de formulário. Append-only: decisões anteriores nunca são sobrescritas.
 *
 * @property array<string,mixed> $payload
 * @property array<string,mixed> $metadata
 * @property string $id
 * @property string $process_instance_id
 * @property string|null $task_projection_id
 * @property string $author_name
 * @property int $revision
 * @property string|null $form_schema_hash
 * @property Carbon $created_at
 */
class FormSubmission extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'form_submission';

    protected $fillable = [
        'process_instance_id', 'task_projection_id', 'stage_version_id', 'form_schema_version_id', 'form_schema_hash',
        'payload', 'metadata', 'author_id', 'author_name', 'revision', 'created_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (FormSubmission $submission) {
            // Único ajuste permitido: vincular a submissão de abertura à tarefa criada pelo engine.
            if (array_diff(array_keys($submission->getDirty()), ['task_projection_id']) !== []) {
                throw new LogicException('Submissões são imutáveis.');
            }
        });
        static::deleting(fn () => throw new LogicException('Submissões são imutáveis.'));
    }

    /** @return BelongsTo<ProcessStageVersion, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProcessStageVersion::class, 'stage_version_id');
    }

    /** @return BelongsTo<FormSchemaVersion, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(FormSchemaVersion::class, 'form_schema_version_id');
    }

    /** @return BelongsTo<ProcessInstance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(ProcessInstance::class, 'process_instance_id');
    }

    /** @return BelongsTo<TaskProjection, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(TaskProjection::class, 'task_projection_id');
    }
}
