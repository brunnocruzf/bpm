<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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

    /**
     * delta_link/next_link ficam ilegíveis se APP_KEY mudar (ou o valor for corrompido). Trata como cursor
     * ausente em vez de derrubar a leitura inteira (tela de usuário, overview, rodada de sync): o código já
     * sabe recarregar do zero quando delta_link/next_link estão vazios.
     */
    public function getAttribute($key)
    {
        if (in_array($key, ['delta_link', 'next_link'], true)) {
            try {
                return parent::getAttribute($key);
            } catch (DecryptException $e) {
                Log::notice('DirectorySyncCursor: valor ilegível (chave divergente ou corrompido); tratado como ausente.', ['resource' => $this->resource, 'field' => $key]);

                return null;
            }
        }

        return parent::getAttribute($key);
    }
}
