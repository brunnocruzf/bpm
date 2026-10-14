<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyRecord extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'idempotency_record';

    protected $fillable = ['idempotency_key', 'user_id', 'scope', 'request_hash', 'response_status', 'response_body', 'created_at'];

    protected function casts(): array
    {
        return ['response_body' => 'array', 'created_at' => 'datetime'];
    }
}
