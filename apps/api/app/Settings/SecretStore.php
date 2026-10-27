<?php

namespace App\Settings;

use App\Models\SecretEntry;
use App\Models\User;
use App\Support\ApiProblem;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Abstração de segredos das integrações. O domínio guarda apenas uma referência:
 *  - db:<uuid>    valor criptografado (AES-256-GCM) com chave dedicada BPM_SECRETS_KEY(_FILE),
 *                 ou, se ausente, com chave derivada da APP_KEY;
 *  - file:<nome>  arquivo montado em BPM_SECRETS_DIR (Docker secret / secret manager / Key Vault CSI).
 * Valores nunca são devolvidos pela API nem gravados em log/auditoria.
 */
class SecretStore
{
    private const FILE_NAME = '/^[A-Za-z0-9_.\-]{1,100}$/';

    public function available(): bool
    {
        return $this->key() !== null;
    }

    public function put(string $purpose, string $value, ?User $by = null, ?string $replaceRef = null): string
    {
        $key = $this->key() ?? throw new ApiProblem(
            409, 'Cofre de segredos não configurado',
            'Configure BPM_SECRETS_KEY_FILE (Docker secret) ou informe uma referência file:<nome> de um segredo montado.',
            'secrets_store_unavailable',
        );
        if ($value === '' || strlen($value) > 16384) {
            throw ApiProblem::validation(['secret' => ['Segredo vazio ou grande demais.']]);
        }
        $attrs = [
            'purpose' => $purpose,
            'ciphertext' => $this->encrypter($key)->encryptString($value),
            'key_id' => $this->keyId($key),
            'fingerprint' => substr(hash_hmac('sha256', $value, $key), 0, 12),
        ];
        $existing = $replaceRef && str_starts_with($replaceRef, 'db:') ? SecretEntry::find(substr($replaceRef, 3)) : null;
        if ($existing && $existing->purpose === $purpose) {
            $existing->update([...$attrs, 'rotated_at' => now()]);

            return 'db:'.$existing->id;
        }

        return 'db:'.SecretEntry::create([...$attrs, 'created_by' => $by?->id])->id;
    }

    public function get(?string $ref): ?string
    {
        if (! $ref) {
            return null;
        }
        if (str_starts_with($ref, 'file:')) {
            return $this->readFile(substr($ref, 5));
        }
        if (! str_starts_with($ref, 'db:')) {
            return null;
        }
        $entry = SecretEntry::find(substr($ref, 3));
        if (! $entry) {
            return null;
        }
        foreach ($this->allKeys() as $key) {
            if ($this->keyId($key) !== $entry->key_id) {
                continue;
            }
            try {
                return $this->encrypter($key)->decryptString($entry->ciphertext);
            } catch (DecryptException) {
                throw new RuntimeException('Segredo ilegível com a chave configurada.');
            }
        }
        throw new RuntimeException('Chave do cofre usada neste segredo não está disponível (rotação incompleta).');
    }

    public function delete(?string $ref): void
    {
        if ($ref && str_starts_with($ref, 'db:')) {
            SecretEntry::whereKey(substr($ref, 3))->delete();
        }
    }

    /** @return array{configured:bool, source:?string, updatedAt:?string, readable:bool} sem revelar o valor */
    public function describe(?string $ref): array
    {
        if (! $ref) {
            return ['configured' => false, 'source' => null, 'updatedAt' => null, 'readable' => false];
        }
        if (str_starts_with($ref, 'file:')) {
            return ['configured' => true, 'source' => 'arquivo montado ('.substr($ref, 5).')', 'updatedAt' => null, 'readable' => $this->readFile(substr($ref, 5)) !== null];
        }
        $entry = str_starts_with($ref, 'db:') ? SecretEntry::find(substr($ref, 3)) : null;

        return ['configured' => (bool) $entry, 'source' => $entry ? 'cofre da aplicação (criptografado)' : null, 'updatedAt' => $entry?->updated_at?->toIso8601String(), 'readable' => (bool) $entry && $this->available()];
    }

    public static function isValidFileRef(string $ref): bool
    {
        return str_starts_with($ref, 'file:') && preg_match(self::FILE_NAME, substr($ref, 5)) === 1;
    }

    /** Recriptografa segredos com a chave atual (após rotação). */
    public function rotate(): int
    {
        $key = $this->key() ?? throw new RuntimeException('Chave atual não configurada.');
        $n = 0;
        foreach (SecretEntry::where('key_id', '!=', $this->keyId($key))->get() as $entry) {
            $plain = $this->get('db:'.$entry->id);
            if ($plain !== null) {
                $entry->update(['ciphertext' => $this->encrypter($key)->encryptString($plain), 'key_id' => $this->keyId($key), 'rotated_at' => now()]);
                $n++;
            }
        }

        return $n;
    }

    private function readFile(string $name): ?string
    {
        if (preg_match(self::FILE_NAME, $name) !== 1) {
            return null;
        }
        $dir = realpath((string) config('bpm.secrets.files_dir', '/run/secrets'));
        $path = $dir ? realpath($dir.DIRECTORY_SEPARATOR.$name) : false;
        if (! $dir || ! $path || ! str_starts_with($path, $dir.DIRECTORY_SEPARATOR) || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $value = trim((string) file_get_contents($path));

        return $value === '' ? null : $value;
    }

    private function key(): ?string
    {
        $raw = config('bpm.secrets.key');
        $file = config('bpm.secrets.key_file');
        if (! $raw && $file && is_readable((string) $file)) {
            $raw = trim((string) file_get_contents((string) $file));
        }

        return $raw ? $this->decodeKey((string) $raw) : $this->fallbackKey();
    }

    /** Sem chave dedicada configurada, deriva uma chave da APP_KEY (os segredos continuam criptografados). */
    private function fallbackKey(): ?string
    {
        $appKey = (string) config('app.key');

        return $appKey !== '' ? hash('sha256', 'bpm-secrets-fallback:'.$appKey, true) : null;
    }

    /** @return list<string> */
    private function allKeys(): array
    {
        $keys = [];
        if ($current = $this->key()) {
            $keys[] = $current;
        }
        foreach ((array) config('bpm.secrets.previous_keys', []) as $prev) {
            $keys[] = $this->decodeKey((string) $prev);
        }
        // Mantém legíveis os segredos gravados com a chave derivada antes de configurar BPM_SECRETS_KEY.
        if (($fallback = $this->fallbackKey()) && ! in_array($fallback, $keys, true)) {
            $keys[] = $fallback;
        }

        return $keys;
    }

    private function decodeKey(string $raw): string
    {
        $raw = str_starts_with($raw, 'base64:') ? substr($raw, 7) : $raw;
        $bytes = base64_decode($raw, true);
        if ($bytes === false || strlen($bytes) !== 32) {
            throw new RuntimeException('BPM_SECRETS_KEY inválida: use 32 bytes em base64.');
        }

        return $bytes;
    }

    private function keyId(string $key): string
    {
        return substr(hash('sha256', 'bpm-secrets:'.$key), 0, 12);
    }

    private function encrypter(string $key): Encrypter
    {
        return new Encrypter($key, 'aes-256-gcm');
    }
}
