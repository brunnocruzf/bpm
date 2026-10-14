<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Credencial local do SuperAdmin (acesso de emergência). Somente o hash da senha é gravado.
 *
 * @property string $id
 * @property string $user_id
 * @property string $password_hash
 * @property Carbon $password_changed_at
 * @property int $failed_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property-read User $user
 */
class LocalCredential extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'password_hash', 'password_changed_at', 'failed_attempts', 'locked_until', 'last_login_at', 'last_login_ip'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['password_changed_at' => 'datetime', 'locked_until' => 'datetime', 'last_login_at' => 'datetime', 'failed_attempts' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
