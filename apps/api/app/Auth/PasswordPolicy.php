<?php

namespace App\Auth;

/**
 * Política e hash da senha local do SuperAdmin. Argon2id quando disponível (bcrypt como fallback,
 * limitado a 72 bytes). A senha nunca é logada, auditada nem devolvida.
 */
final class PasswordPolicy
{
    public const MAX_LENGTH = 128;

    private static ?string $dummy = null;

    /** @return list<string> mensagens de violação (vazio = senha aceita) */
    public static function violations(string $password, string $username): array
    {
        $errors = [];
        $min = (int) config('bpm.auth.superadmin.password_min_length', 14);
        $len = mb_strlen($password);
        if ($len < $min) {
            $errors[] = "Mínimo de $min caracteres.";
        }
        if ($len > self::MAX_LENGTH || (self::algorithm() === PASSWORD_BCRYPT && strlen($password) > 72)) {
            $errors[] = 'Senha longa demais.';
        }
        $classes = (int) preg_match('/[a-z]/', $password) + (int) preg_match('/[A-Z]/', $password)
            + (int) preg_match('/\d/', $password) + (int) preg_match('/[^a-zA-Z\d]/', $password);
        if ($classes < 3) {
            $errors[] = 'Use pelo menos 3 tipos: minúsculas, maiúsculas, números e símbolos.';
        }
        if ($username !== '' && str_contains(mb_strtolower($password), mb_strtolower($username))) {
            $errors[] = 'A senha não pode conter o nome de usuário.';
        }
        if (count(array_unique(mb_str_split($password))) < 6) {
            $errors[] = 'Senha com pouca variedade de caracteres.';
        }

        return $errors;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    public static function verify(string $password, ?string $hash): bool
    {
        // sem conta: verifica contra um hash fictício para não revelar a existência do usuário pelo tempo de resposta
        return password_verify($password, $hash ?? self::dummyHash()) && $hash !== null;
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    public static function generate(int $length = 24): string
    {
        $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '-_.:+!@#%'];
        $all = implode('', $sets);
        $chars = array_map(fn (string $s) => $s[random_int(0, strlen($s) - 1)], $sets);
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function dummyHash(): string
    {
        return self::$dummy ??= password_hash(bin2hex(random_bytes(16)), self::algorithm());
    }
}
