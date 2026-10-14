<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $kind
 * @property string $disk
 * @property string $object_key
 * @property string $content_type
 * @property int $size
 * @property int $width
 * @property int $height
 * @property string $sha256
 * @property string $av_status
 * @property Carbon $created_at
 */
class BrandingAsset extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'branding_asset';

    protected $fillable = ['kind', 'disk', 'object_key', 'content_type', 'size', 'width', 'height', 'sha256', 'av_status', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }
}
