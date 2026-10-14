<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $directory_group_id
 * @property string $user_id
 * @property string $source
 * @property Carbon $synced_at
 * @property Carbon|null $removed_at
 */
class DirectoryMembership extends Model
{
    public $timestamps = false;

    protected $table = 'directory_membership';

    protected $fillable = ['directory_group_id', 'user_id', 'source', 'synced_at', 'removed_at'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    /** @return BelongsTo<DirectoryGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(DirectoryGroup::class, 'directory_group_id');
    }
}
