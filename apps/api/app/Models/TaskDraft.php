<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TaskDraft extends Model
{
    use HasUuids;

    protected $table = 'task_draft';

    protected $fillable = ['task_projection_id', 'user_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
