<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Metadados da foto (miniatura) importada do Entra ID. Os bytes ficam no disco privado.
 *
 * @property string $user_id
 * @property string|null $disk
 * @property string|null $object_key
 * @property string|null $content_type
 * @property int|null $size
 * @property string|null $sha256
 * @property string|null $graph_etag
 * @property string $status
 * @property string|null $error_code
 * @property Carbon|null $checked_at
 * @property Carbon|null $fetched_at
 */
class UserPhoto extends Model
{
    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public const ERROR = 'error';

    protected $table = 'user_photo';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'disk', 'object_key', 'content_type', 'size', 'width', 'height', 'sha256', 'graph_etag', 'status', 'error_code', 'checked_at', 'fetched_at'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'fetched_at' => 'datetime', 'size' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Versão pública (cache busting) derivada do conteúdo. */
    public function version(): ?string
    {
        return $this->status === self::PRESENT && $this->sha256 ? substr($this->sha256, 0, 12) : null;
    }
}
