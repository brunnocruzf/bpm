<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Conexão com um sistema externo via REST (ex.: API do Dynamics AX 2012 — apidax). A credencial
 * fica no cofre (secret_ref) e nunca é devolvida pela API.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string $system
 * @property string $base_url
 * @property string $environment
 * @property bool $enabled
 * @property int $timeout_seconds
 * @property string $auth_type none | bearer_token | basic | api_key_header | login | oauth2_client_credentials
 * @property array<string,mixed>|null $auth_config
 * @property string|null $secret_ref
 * @property string|null $openapi_url
 * @property string|null $openapi_hash
 * @property string|null $openapi_title
 * @property string|null $openapi_version
 * @property Carbon|null $openapi_imported_at
 * @property Carbon|null $openapi_checked_at
 * @property bool $allow_writes
 * @property string|null $writes_approval
 * @property array<string,mixed>|null $last_test
 * @property Carbon|null $last_tested_at
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $endpoints_count
 * @property-read int|null $enabled_endpoints_count
 */
class IntegrationConnection extends Model
{
    use HasUuids;

    public const AUTH_TYPES = ['none', 'bearer_token', 'basic', 'api_key_header', 'login', 'oauth2_client_credentials'];

    public const ENVIRONMENTS = ['desenvolvimento', 'homologacao', 'producao'];

    protected $table = 'integration_connection';

    protected $attributes = ['enabled' => false, 'allow_writes' => false, 'auth_type' => 'none', 'environment' => 'homologacao', 'timeout_seconds' => 15];

    protected $fillable = ['key', 'name', 'system', 'base_url', 'environment', 'enabled', 'timeout_seconds', 'auth_type', 'auth_config', 'secret_ref',
        'openapi_url', 'openapi_hash', 'openapi_title', 'openapi_version', 'openapi_imported_at', 'openapi_checked_at', 'allow_writes', 'writes_approval',
        'last_test', 'last_tested_at', 'updated_by'];

    protected $hidden = ['secret_ref'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'allow_writes' => 'boolean', 'auth_config' => 'array', 'last_test' => 'array', 'timeout_seconds' => 'integer',
            'openapi_imported_at' => 'datetime', 'openapi_checked_at' => 'datetime', 'last_tested_at' => 'datetime'];
    }

    /** @return HasMany<IntegrationEndpoint, $this> */
    public function endpoints(): HasMany
    {
        return $this->hasMany(IntegrationEndpoint::class, 'connection_id');
    }
}
