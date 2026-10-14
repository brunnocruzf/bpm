<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sessão opaca emitida pelo Laravel (login SuperAdmin). Somente o sha256 do token é gravado.
 *
 * @property string $id
 * @property string $user_id
 * @property string $method
 * @property string $token_hash
 * @property string|null $ip
 * @property Carbon $created_at
 * @property Carbon $last_used_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property-read User $user
 */
class AuthSession extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['user_id', 'method', 'token_hash', 'ip', 'created_at', 'last_used_at', 'expires_at', 'revoked_at', 'revoked_reason'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<AuthSession>  $query
     * @return Builder<AuthSession>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
