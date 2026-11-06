<?php

namespace App\Support;

/**
 * Redação central: mascara dados sensíveis antes de logs, auditoria, registros de integração e
 * respostas de erro. Atua por nome de chave (senha, token, segredo, PII de contato) e por padrão
 * de valor (JWT, Bearer, chaves PEM, credenciais em URL), para cobrir payloads externos.
 */
final class Masker
{
    private const SENSITIVE = [
        'password', 'senha', 'secret', 'token', 'authorization', 'cookie', 'apikey', 'api_key', 'client_secret',
        'private_key', 'privatekey', 'assertion', 'credential', 'license', 'deltalink', 'delta_link', 'nextlink', 'next_link',
        'email', 'telefone', 'phone', 'cpf', 'cnpj',
        'accountkey', 'account_key', 'sastoken', 'sas_token', 'connectionstring', 'connection_string', 'sharedaccesssignature',
        'recoverykey', 'recovery_key',
    ];

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function mask(array $data, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['…'];
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (self::isSensitiveKey((string) $key) && $value !== null && $value !== '' && ! is_bool($value)) {
                $out[$key] = '***';
            } elseif (is_array($value)) {
                $out[$key] = self::mask($value, $depth + 1);
            } elseif (is_string($value)) {
                $value = self::scrub($value);
                $out[$key] = mb_strlen($value) > 300 ? mb_substr($value, 0, 300).'…' : $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Remove valores com formato de credencial mesmo quando a chave não denuncia. */
    public static function scrub(string $value): string
    {
        $value = preg_replace('/-----BEGIN [A-Z ]+-----[\s\S]*?(-----END [A-Z ]+-----|$)/', '[PEM redigido]', $value) ?? $value;
        $value = preg_replace('/\beyJ[A-Za-z0-9_\-]{5,}\.[A-Za-z0-9_\-]{5,}\.[A-Za-z0-9_\-]*/', '[JWT redigido]', $value) ?? $value;
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=\-:]+/i', 'Bearer ***', $value) ?? $value;
        $value = preg_replace('#(https?://)[^/@\s:]+:[^/@\s]+@#i', '$1***@', $value) ?? $value;
        $value = preg_replace('/\b(AccountKey|SharedAccessSignature)=[^;\s"]+/i', '$1=***', $value) ?? $value;
        $value = preg_replace('/\bAGE-SECRET-KEY-1[0-9A-Z]+/', '[chave de recuperação redigida]', $value) ?? $value;
        $value = preg_replace('/([?&](\$?deltatoken|\$?skiptoken|code|client_secret|sig|signature)=)[^&\s"]+/i', '$1***', $value) ?? $value;

        return $value;
    }

    public static function error(\Throwable|string $error): string
    {
        $message = is_string($error) ? $error : $error->getMessage();
        // Remove credenciais embutidas em URLs e cabeçalhos.
        $message = preg_replace('#(https?://)[^/@\s]+@#i', '$1***@', $message) ?? $message;
        $message = preg_replace('/(authorization|password|secret|token)\s*[:=]\s*\S+/i', '$1=***', $message) ?? $message;
        $message = self::scrub($message);

        return mb_substr($message, 0, 1000);
    }

    /** Mascara endereço de e-mail para exibição (ana.souza@x.com → a***a@x.com). */
    public static function emailHint(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email ? '***' : null;
        }
        [$local, $domain] = explode('@', $email, 2);
        $hint = mb_strlen($local) <= 2 ? mb_substr($local, 0, 1).'*' : mb_substr($local, 0, 1).'***'.mb_substr($local, -1);

        return $hint.'@'.$domain;
    }
}
