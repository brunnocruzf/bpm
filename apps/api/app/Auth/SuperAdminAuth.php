<?php

namespace App\Auth;

use App\Audit\AuditContext;
use App\Models\AuthSession;
use App\Models\LocalCredential;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Acesso de emergência (SuperAdmin) por usuário e senha locais — ADR-0015.
 *  - Senha: somente hash (PasswordPolicy), definida apenas pelo comando `bpm:superadmin`.
 *  - Tentativas: limite por usuário+IP e por IP (429) + bloqueio da conta após falhas consecutivas.
 *    Resposta genérica para usuário inexistente, senha errada, conta bloqueada ou inativa.
 *  - Sessão: token opaco aleatório (somente o sha256 é gravado), expiração absoluta e por inatividade,
 *    revogável (logout, troca de senha, desativação). Tudo auditado; senha/token nunca são registrados.
 */
class SuperAdminAuth
{
    public const TOKEN_PREFIX = 'bpmsa_';

    private const ANONYMOUS = 'tentativa de login (não autenticado)';

    private const GENERIC = 'Usuário ou senha inválidos, ou acesso temporariamente bloqueado.';

    /** @return array{token: string, expiresAt: string, idleMinutes: int, user: array{name: string, username: string|null}} */
    public function attempt(string $username, string $password, Request $request): array
    {
        if (! AuthMethods::superAdminEnabled($request)) {
            throw ApiProblem::notFound('Acesso SuperAdmin indisponível neste ambiente.');
        }
        $cfg = (array) config('bpm.auth.superadmin');
        $username = mb_strtolower(trim($username));
        $ip = (string) $request->ip();
        $window = (int) $cfg['attempt_window_minutes'] * 60;
        $pairKey = 'superadmin-login:'.sha1($username.'|'.$ip);
        $ipKey = 'superadmin-login-ip:'.sha1($ip);

        if (RateLimiter::tooManyAttempts($pairKey, (int) $cfg['max_attempts']) || RateLimiter::tooManyAttempts($ipKey, (int) $cfg['max_attempts'] * 4)) {
            $retry = max(RateLimiter::availableIn($pairKey), RateLimiter::availableIn($ipKey));
            if (Cache::add('superadmin-throttled:'.sha1($pairKey), 1, $retry)) {
                $this->auditFailure(null, $username, 'throttled');
            }
            throw new ApiProblem(429, 'Muitas tentativas', 'Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.', 'login_throttled', [], ['retryAfterSeconds' => $retry]);
        }

        $user = $username === '' ? null : User::where('username', $username)->where('auth_source', User::SOURCE_SUPERADMIN)->first();
        $credential = $user ? LocalCredential::where('user_id', $user->id)->first() : null;
        $valid = PasswordPolicy::verify($password, $credential?->password_hash);

        if ($user === null || $credential === null) {
            $this->reject(null, null, $username, 'invalid_credentials', [$pairKey, $ipKey], $window);
        }
        $reason = match (true) {
            $credential->isLocked() => 'locked',
            ! $valid => 'invalid_credentials',
            $user->is_active === false => 'inactive',
            default => null,
        };
        if ($reason !== null) {
            $this->reject($user, $credential, $username, $reason, [$pairKey, $ipKey], $window);
        }
        RateLimiter::clear($pairKey);

        return DB::transaction(function () use ($user, $credential, $password, $ip, $cfg) {
            $credential->forceFill([
                'password_hash' => PasswordPolicy::needsRehash($credential->password_hash) ? PasswordPolicy::hash($password) : $credential->password_hash,
                'failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $ip,
            ])->save();

            // limite de sessões simultâneas: revoga as mais antigas
            $active = AuthSession::active()->where('user_id', $user->id)->orderByDesc('created_at')->get();
            foreach ($active->slice(max(0, (int) $cfg['max_sessions'] - 1)) as $old) {
                $old->forceFill(['revoked_at' => now(), 'revoked_reason' => 'session_limit'])->save();
            }

            $token = self::TOKEN_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $expires = now()->addMinutes((int) $cfg['session_minutes']);
            $session = AuthSession::create([
                'user_id' => $user->id, 'method' => AuthMethods::SUPERADMIN, 'token_hash' => hash('sha256', $token), 'ip' => $ip,
                'created_at' => now(), 'last_used_at' => now(), 'expires_at' => $expires,
            ]);
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            AuditLogger::record('auth.login', 'user', $user->id, ['method' => AuthMethods::SUPERADMIN, 'session' => $session->id], null, $user, context: ['object_name' => $user->name]);

            return [
                'token' => $token,
                'expiresAt' => $expires->toIso8601String(),
                'idleMinutes' => (int) $cfg['idle_minutes'],
                'user' => ['name' => $user->name, 'username' => $user->username],
            ];
        });
    }

    /** Resolve o usuário de um token de sessão SuperAdmin válido (ou null). */
    public function resolve(string $token, Request $request): ?User
    {
        if (! str_starts_with($token, self::TOKEN_PREFIX) || ! AuthMethods::superAdminEnabled($request)) {
            return null;
        }
        $session = AuthSession::where('token_hash', hash('sha256', $token))->first();
        $idle = (int) config('bpm.auth.superadmin.idle_minutes', 30);
        if (! $session || $session->revoked_at || $session->expires_at->isPast()) {
            return null;
        }
        if ($session->last_used_at->lt(now()->subMinutes($idle))) {
            $session->forceFill(['revoked_at' => now(), 'revoked_reason' => 'idle_timeout'])->save();

            return null;
        }
        $user = $session->user;
        if ($user->auth_source !== User::SOURCE_SUPERADMIN || ! LocalCredential::where('user_id', $user->id)->exists()) {
            return null;
        }
        if ($session->last_used_at->lt(now()->subMinute())) {
            $session->forceFill(['last_used_at' => now()])->save();
        }
        $request->attributes->set('bpm.auth', [
            'method' => AuthMethods::SUPERADMIN,
            'expiresAt' => $session->expires_at->toIso8601String(),
            'idleMinutes' => $idle,
        ]);

        return $user;
    }

    public function revokeToken(string $token, string $reason): void
    {
        AuthSession::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    public function revokeAll(User $user, string $reason): int
    {
        return AuthSession::active()->where('user_id', $user->id)->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    /** @param list<string> $limiterKeys */
    private function reject(?User $user, ?LocalCredential $credential, string $typed, string $reason, array $limiterKeys, int $window): never
    {
        foreach ($limiterKeys as $key) {
            RateLimiter::hit($key, $window);
        }
        if ($user && $credential && $reason === 'invalid_credentials') {
            $this->registerFailure($credential, $user);
        }
        $this->auditFailure($user, $typed, $reason);

        throw new ApiProblem(401, 'Não autenticado', self::GENERIC, 'invalid_credentials');
    }

    private function registerFailure(LocalCredential $credential, User $user): void
    {
        $threshold = (int) config('bpm.auth.superadmin.lockout_threshold', 10);
        $failed = $credential->failed_attempts + 1;
        $lockedUntil = $failed >= $threshold ? now()->addMinutes((int) config('bpm.auth.superadmin.lockout_minutes', 30)) : null;
        $credential->forceFill(['failed_attempts' => $lockedUntil ? 0 : $failed, 'locked_until' => $lockedUntil])->save();
        if ($lockedUntil instanceof Carbon) {
            $this->revokeAll($user, 'locked');
            AuditContext::as(self::ANONYMOUS, fn () => AuditLogger::record('auth.superadmin_locked', 'user', $user->id, ['failedAttempts' => $failed, 'lockedUntil' => $lockedUntil->toIso8601String()], outcome: 'blocked', context: ['object_name' => $user->name]));
        }
    }

    /** Nunca grava a senha; o usuário digitado só aparece se corresponder ao SuperAdmin (evita gravar senha digitada no campo errado). */
    private function auditFailure(?User $user, string $typed, string $reason): void
    {
        // ator = tentativa não autenticada (nunca o titular da conta)
        AuditContext::as(self::ANONYMOUS, fn () => AuditLogger::record('auth.login_failed', 'user', $user?->id, array_filter([
            'method' => AuthMethods::SUPERADMIN,
            'reason' => $reason,
            'typedUserRef' => $user ? null : ($typed === '' ? null : substr(hash('sha256', $typed), 0, 12)),
        ]), outcome: $reason === 'throttled' || $reason === 'locked' ? 'blocked' : 'failure', context: ['object_name' => $user !== null ? $user->name : 'SuperAdmin (usuário não reconhecido)']));
    }
}
