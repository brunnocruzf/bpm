<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Histórico de validações/testes de uma configuração (descoberta OIDC, teste de token, teste de
 * conexão/permissões do Graph, teste SMTP/Graph, e-mail de teste...). Valores já redigidos.
 *
 * @property string $id
 * @property string $setting_key
 * @property string|null $step
 * @property bool $ok
 * @property string|null $code
 * @property string|null $message
 * @property list<array<string,mixed>>|null $checks
 * @property array<string,mixed>|null $details
 * @property string|null $provider
 * @property int|null $duration_ms
 * @property int|null $setting_version
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string|null $correlation_id
 * @property Carbon $created_at
 */
class SettingValidation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'setting_validation';

    protected $fillable = ['setting_key', 'step', 'ok', 'code', 'message', 'checks', 'details', 'provider', 'duration_ms', 'setting_version', 'actor_id', 'actor_name', 'correlation_id', 'created_at'];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'checks' => 'array', 'details' => 'array', 'created_at' => 'datetime', 'duration_ms' => 'integer', 'setting_version' => 'integer'];
    }
}
