<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Segredo criptografado (AES-256-GCM, chave dedicada). Nunca serializado em respostas.
 *
 * @property string $id
 * @property string $purpose
 * @property string $ciphertext
 * @property string $key_id
 * @property string $fingerprint
 * @property Carbon|null $updated_at
 */
class SecretEntry extends Model
{
    use HasUuids;

    protected $table = 'secret_entry';

    protected $fillable = ['purpose', 'ciphertext', 'key_id', 'fingerprint', 'created_by', 'rotated_at'];

    protected $hidden = ['ciphertext'];
}
