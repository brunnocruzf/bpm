<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Checkpoint opaco do delta query (criptografado em repouso; nunca exposto pela API).
 *
 * @property int $id
 * @property string $resource
 * @property string|null $delta_link
 * @property string|null $next_link
 * @property string|null $next_link_run_id
 * @property string|null $scope_hash
 * @property Carbon|null $delta_saved_at
 * @property bool $full_sync_required
 */
class DirectorySyncCursor extends Model
{
    protected $table = 'directory_sync_cursor';

    protected $fillable = ['resource', 'delta_link', 'next_link', 'next_link_run_id', 'scope_hash', 'delta_saved_at', 'full_sync_required'];

    protected $hidden = ['delta_link', 'next_link'];

    protected function casts(): array
    {
        return ['delta_link' => 'encrypted', 'next_link' => 'encrypted', 'delta_saved_at' => 'datetime', 'full_sync_required' => 'boolean'];
    }
}
