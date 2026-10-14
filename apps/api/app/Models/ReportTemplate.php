<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Modelo de relatório versionado (versão ativa é imutável; alterar = nova versão).
 *
 * @property string $id
 * @property string $key
 * @property int $version
 * @property string $name
 * @property string $kind
 * @property string $engine
 * @property string $status
 * @property array<string,mixed> $options
 * @property string|null $file_disk
 * @property string|null $file_key
 * @property string|null $file_sha256
 * @property int|null $file_size
 * @property string|null $created_by_name
 * @property Carbon|null $activated_at
 * @property Carbon|null $created_at
 */
class ReportTemplate extends Model
{
    use HasUuids;

    protected $table = 'report_template';

    protected $fillable = ['key', 'version', 'name', 'kind', 'engine', 'status', 'options', 'file_disk', 'file_key', 'file_sha256', 'file_size', 'created_by', 'created_by_name', 'activated_by', 'activated_at'];

    protected $attributes = ['options' => '{}', 'engine' => 'native', 'status' => 'draft'];

    protected function casts(): array
    {
        return ['options' => 'array', 'activated_at' => 'datetime', 'version' => 'integer'];
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options ?? [], $key, $default);
    }
}
