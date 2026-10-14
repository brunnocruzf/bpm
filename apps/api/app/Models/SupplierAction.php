<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Ação do plano corretivo/preventivo do fornecedor (atividades 30 / 30.1).
 *
 * @property string $id
 * @property string $process_instance_id
 * @property string $status
 * @property string $kind
 * @property string $action_text
 * @property string $responsible
 * @property string|null $location
 * @property string|null $notes
 * @property string|null $completion_notes
 * @property Carbon|null $due_date
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
class SupplierAction extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const SUPERSEDED = 'superseded';

    protected $table = 'supplier_action';

    protected $fillable = [
        'process_instance_id', 'source_submission_id', 'kind', 'action_text', 'responsible', 'due_date', 'location', 'notes',
        'status', 'position', 'completed_by', 'completed_at', 'completion_notes',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completed_at' => 'datetime'];
    }

    /** @return HasMany<Attachment, $this> */
    public function evidences(): HasMany
    {
        return $this->hasMany(Attachment::class, 'supplier_action_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
