<?php

namespace App\Backup;

use App\Support\ApiProblem;
use Illuminate\Support\Facades\Process;

/**
 * Chave de recuperação (age, X25519). O servidor guarda só a parte pública: gerar backup não exige a privada, e restaurar
 * exige. A privada existe em memória durante a geração e a restauração e nunca é gravada aberta.
 */
class RecoveryKey
{
    public const PRIVATE_PATTERN = '/^AGE-SECRET-KEY-1[0-9A-Z]{58}$/';

    public const PUBLIC_PATTERN = '/^age1[0-9a-z]{58}$/';

    /** @return array{privateKey:string, publicKey:string, fingerprint:string} */
    public function generate(): array
    {
        $result = Process::run(['age-keygen']);
        $private = $result->successful() && preg_match('/^AGE-SECRET-KEY-1[0-9A-Z]+$/m', $result->output(), $m) ? $m[0] : null;
        if ($private === null) {
            throw ApiProblem::unavailable('A ferramenta de chaves não está disponível nesta instalação. Reconstrua a imagem da API.', 'backup_tool_missing');
        }
        $public = $this->publicOf($private);

        return ['privateKey' => $private, 'publicKey' => $public, 'fingerprint' => self::fingerprint($public)];
    }

    /** Parte pública da chave informada; recusa o que não for uma chave válida. */
    public function publicOf(string $privateKey): string
    {
        $key = trim($privateKey);
        if (preg_match(self::PRIVATE_PATTERN, $key) !== 1) {
            throw ApiProblem::validation(['recoveryKey' => ['Chave de recuperação inválida. Cole a chave completa, começando em AGE-SECRET-KEY-1.']]);
        }
        $result = Process::input($key."\n")->run(['age-keygen', '-y']);
        $public = trim($result->output());
        if (! $result->successful() || preg_match(self::PUBLIC_PATTERN, $public) !== 1) {
            throw ApiProblem::validation(['recoveryKey' => ['Chave de recuperação inválida. Cole a chave completa, começando em AGE-SECRET-KEY-1.']]);
        }

        return $public;
    }

    public static function fingerprint(string $publicKey): string
    {
        return substr($publicKey, 0, 8).'…'.substr($publicKey, -4);
    }
}
