<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $group_id
 * @property string $user_id
 * @property string $source
 * @property string|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property-read BpmGroup $group
 * @property-read User $user
 */
class BpmGroupMembership extends Model
{
    protected $table = 'bpm_group_membership';

    public $timestamps = false;

    protected $fillable = ['group_id', 'user_id', 'source', 'created_by', 'created_by_name', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<BpmGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(BpmGroup::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
