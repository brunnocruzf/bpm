<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Metadados do anexo. O binário fica no storage S3-compatível (ou disco local em dev). */
class Attachment extends Model
{
    use HasUuids;

    protected $table = 'attachment';

    protected $fillable = [
        'disk', 'object_key', 'original_name', 'content_type', 'size', 'sha256', 'uploader_id', 'process_instance_id',
        'task_projection_id', 'supplier_action_id', 'field_key', 'av_status', 'retention_until',
    ];

    protected $hidden = ['disk', 'object_key'];

    protected function casts(): array
    {
        return ['retention_until' => 'date', 'size' => 'integer'];
    }
}
