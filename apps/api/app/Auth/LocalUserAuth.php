<?php

namespace App\Auth;

use App\Models\AuthSession;
use App\Models\LocalCredential;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class LocalUserAuth
{
    public const TOKEN_PREFIX = 'bpml_';

    public function attempt(string $login, string $password, Request $request): array
    {
        $key = 'local-login:'.sha1(mb_strtolower(trim($login)).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new ApiProblem(429, 'Muitas tentativas', 'Muitas tentativas de acesso. Aguarde e tente novamente.', 'login_throttled');
        }
        $user = User::where('auth_source', 'local')->where(fn ($q) => $q->where('username', mb_strtolower(trim($login)))->orWhere('email', mb_strtolower(trim($login))))->first();
        $credential = $user ? LocalCredential::where('user_id', $user->id)->first() : null;
        if (! $user || ! $credential || $user->is_active === false || $credential->isLocked() || ! PasswordPolicy::verify($password, $credential->password_hash)) {
            RateLimiter::hit($key, 900);
            throw new ApiProblem(401, 'Não autenticado', 'Usuário ou senha inválidos, ou acesso temporariamente bloqueado.', 'invalid_credentials');
        }
        RateLimiter::clear($key);
        return DB::transaction(function () use ($user, $credential, $request) {
            $token = self::TOKEN_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $expires = now()->addMinutes(240);
            AuthSession::create(['user_id' => $user->id, 'method' => 'local', 'token_hash' => hash('sha256', $token), 'ip' => $request->ip(), 'created_at' => now(), 'last_used_at' => now(), 'expires_at' => $expires]);
            $credential->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip(), 'failed_attempts' => 0])->save();
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            AuditLogger::record('auth.login', 'user', $user->id, ['method' => 'local'], null, $user, context: ['object_name' => $user->name]);
            return ['token' => $token, 'expiresAt' => $expires->toIso8601String(), 'idleMinutes' => 30, 'user' => ['name' => $user->name, 'username' => $user->username]];
        });
    }

    public function resolve(string $token, Request $request): ?User
    {
        if (! str_starts_with($token, self::TOKEN_PREFIX)) return null;
        $s = AuthSession::where('token_hash', hash('sha256', $token))->where('method', 'local')->whereNull('revoked_at')->first();
        if (! $s || $s->expires_at->isPast() || $s->last_used_at->lt(now()->subMinutes(30)) || $s->user->auth_source !== 'local') return null;
        $s->update(['last_used_at' => now()]);
        $request->attributes->set('bpm.auth', ['method' => 'local', 'expiresAt' => $s->expires_at->toIso8601String()]);
        return $s->user;
    }
}
