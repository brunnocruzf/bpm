<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $email
 */
class BpmGroup extends Model
{
    use HasUuids;

    protected $table = 'bpm_groups';

    protected $fillable = ['key', 'name', 'description', 'entra_group_id', 'email'];
}
