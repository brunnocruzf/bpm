<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Configuração organizacional tipada (somente valores não secretos).
 *
 * @property string $id
 * @property string $key
 * @property array<string,mixed> $value
 * @property int $version
 * @property string $status
 * @property string|null $status_detail
 * @property array<string,mixed>|null $last_validation
 * @property Carbon|null $last_validated_at
 * @property Carbon|null $activated_at
 * @property string|null $updated_by
 * @property string|null $updated_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AppSetting extends Model
{
    use HasUuids;

    public const CONFIGURED = 'configured';

    public const INCOMPLETE = 'incomplete';

    public const TEST_PENDING = 'test_pending';

    public const ERROR = 'error';

    public const DISABLED = 'disabled';

    protected $table = 'app_setting';

    protected $fillable = ['key', 'value', 'version', 'status', 'status_detail', 'last_validation', 'last_validated_at', 'activated_at', 'updated_by', 'updated_by_name'];

    protected $attributes = ['value' => '{}', 'version' => 0, 'status' => self::INCOMPLETE];

    protected function casts(): array
    {
        return ['value' => 'array', 'last_validation' => 'array', 'last_validated_at' => 'datetime', 'activated_at' => 'datetime', 'version' => 'integer'];
    }
}
