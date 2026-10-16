<?php

namespace App\Auth;

use App\Models\AuthSession;
use App\Models\LocalCredential;
use App\Models\LocalIdentityToken;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class LocalUserAuth
{
    public const TOKEN_PREFIX = 'bpml_';

    public function __construct(private readonly Notifier $notifier) {}

    /** @return array{token:string,expiresAt:string,idleMinutes:int,user:array{name:string,username:?string}} */
    public function attempt(string $login, string $password, Request $request): array
    {
        $login = mb_strtolower(trim($login));
        $ip = (string) $request->ip();
        $pairKey = 'local-login:'.sha1($login.'|'.$ip);
        $ipKey = 'local-login-ip:'.sha1($ip);
        $maxAttempts = (int) config('bpm.auth.local.max_attempts', 5);
        if (RateLimiter::tooManyAttempts($pairKey, $maxAttempts) || RateLimiter::tooManyAttempts($ipKey, $maxAttempts * 4)) {
            throw new ApiProblem(429, 'Muitas tentativas', 'Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.', 'login_throttled');
        }

        $user = User::where('auth_source', 'local')->where(fn ($q) => $q->whereRaw('LOWER(username) = ?', [$login])->orWhereRaw('LOWER(email) = ?', [$login]))->first();
        $credential = $user ? LocalCredential::where('user_id', $user->id)->first() : null;
        $valid = PasswordPolicy::verify($password, $credential?->password_hash);
        $reason = match (true) {
            $user === null || $credential === null || ! $valid => 'invalid_credentials',
            $credential->isLocked() => 'locked',
            $user->is_active !== true => 'inactive',
            default => null,
        };
        if ($reason !== null) {
            RateLimiter::hit($pairKey, (int) config('bpm.auth.local.attempt_window_seconds', 900));
            RateLimiter::hit($ipKey, (int) config('bpm.auth.local.attempt_window_seconds', 900));
            if ($credential) {
                $failed = (int) $credential->failed_attempts + 1;
                $threshold = (int) config('bpm.auth.local.lockout_threshold', 10);
                $lockedUntil = $failed >= $threshold ? now()->addMinutes((int) config('bpm.auth.local.lockout_minutes', 30)) : null;
                $credential->forceFill(['failed_attempts' => $failed, 'locked_until' => $lockedUntil])->save();
            }
            AuditLogger::record('auth.login_failed', 'user', $user?->id, ['method' => 'local', 'reason' => $reason], null, outcome: 'failure', context: ['object_name' => $user?->name]);
            throw new ApiProblem(401, 'Não autenticado', 'Usuário ou senha inválidos, ou acesso temporariamente bloqueado.', 'invalid_credentials');
        }

        RateLimiter::clear($pairKey);
        $sessionMinutes = (int) config('bpm.auth.local.session_minutes', 240);

        return DB::transaction(function () use ($user, $credential, $password, $ip, $sessionMinutes) {
            $token = self::TOKEN_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $expires = now()->addMinutes($sessionMinutes);
            $credential->forceFill([
                'password_hash' => PasswordPolicy::needsRehash($credential->password_hash) ? PasswordPolicy::hash($password) : $credential->password_hash,
                'last_login_at' => now(), 'last_login_ip' => $ip, 'failed_attempts' => 0, 'locked_until' => null,
            ])->save();
            $active = AuthSession::active()->where('user_id', $user->id)->orderByDesc('created_at')->get();
            foreach ($active->slice(max(0, (int) config('bpm.auth.local.max_sessions', 3) - 1)) as $old) {
                $old->forceFill(['revoked_at' => now(), 'revoked_reason' => 'session_limit'])->save();
            }
            AuthSession::create(['user_id' => $user->id, 'method' => 'local', 'token_hash' => hash('sha256', $token), 'ip' => $ip, 'created_at' => now(), 'last_used_at' => now(), 'expires_at' => $expires]);
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            AuditLogger::record('auth.login', 'user', $user->id, ['method' => 'local'], null, $user, context: ['object_name' => $user->name]);

            return ['token' => $token, 'expiresAt' => $expires->toIso8601String(), 'idleMinutes' => (int) config('bpm.auth.local.idle_minutes', 30), 'user' => ['name' => $user->name, 'username' => $user->username]];
        });
    }

    public function resolve(string $token, Request $request): ?User
    {
        if (! str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }
        $session = AuthSession::where('token_hash', hash('sha256', $token))->where('method', 'local')->whereNull('revoked_at')->first();
        if (! $session || $session->expires_at->isPast()) {
            return null;
        }
        $idle = (int) config('bpm.auth.local.idle_minutes', 30);
        if ($session->last_used_at->lt(now()->subMinutes($idle)) || $session->user->auth_source !== 'local' || $session->user->is_active !== true) {
            $session->forceFill(['revoked_at' => now(), 'revoked_reason' => $session->user->is_active === true ? 'idle_timeout' : 'account_disabled'])->save();

            return null;
        }
        if ($session->last_used_at->lt(now()->subMinute())) {
            $session->forceFill(['last_used_at' => now()])->save();
        }
        $request->attributes->set('bpm.auth', ['method' => 'local', 'expiresAt' => $session->expires_at->toIso8601String(), 'idleMinutes' => $idle]);

        return $session->user;
    }

    public function issueInvitation(User $user, User $actor): bool
    {
        $queued = $this->issueLink($user, 'invitation', $actor, '/login/local/activate?token=');
        AuditLogger::record('identity.local_invitation_issued', 'user', $user->id, ['expiresInMinutes' => 60], null, $actor, context: ['object_name' => $user->name]);

        return $queued;
    }

    public function requestPasswordReset(string $login): void
    {
        $login = mb_strtolower(trim($login));
        $user = User::where('auth_source', 'local')->where('is_active', true)
            ->where(fn ($q) => $q->whereRaw('LOWER(username) = ?', [$login])->orWhereRaw('LOWER(email) = ?', [$login]))->first();
        if ($user) {
            $this->issueLink($user, 'password_reset', null, '/login/local/reset?token=');
            AuditLogger::record('identity.local_password_reset_requested', 'user', $user->id, ['method' => 'local'], null, outcome: 'success');
        }
    }

    public function activate(string $token, string $password): void
    {
        $identityToken = $this->validIdentityToken($token, 'invitation');
        $user = $identityToken->user;
        $errors = PasswordPolicy::violations($password, (string) $user->username);
        if ($errors) {
            throw ApiProblem::validation(['password' => $errors]);
        }
        DB::transaction(function () use ($identityToken, $user, $password) {
            $identityToken->forceFill(['used_at' => now()])->save();
            LocalIdentityToken::where('user_id', $user->id)->where('purpose', 'invitation')->whereNull('used_at')->whereKeyNot($identityToken->id)->update(['used_at' => now()]);
            LocalCredential::where('user_id', $user->id)->update(['password_hash' => PasswordPolicy::hash($password), 'password_changed_at' => now(), 'failed_attempts' => 0, 'locked_until' => null, 'activated_at' => now(), 'updated_at' => now()]);
            $user->forceFill(['is_active' => true, 'disabled_at' => null, 'disabled_reason' => null])->save();
            AuditLogger::record('identity.local_user_activated', 'user', $user->id, ['method' => 'invitation'], null, context: ['object_name' => $user->name]);
        });
    }

    public function resetPassword(string $token, string $password): void
    {
        $identityToken = $this->validIdentityToken($token, 'password_reset');
        $user = $identityToken->user;
        $errors = PasswordPolicy::violations($password, (string) $user->username);
        if ($errors) {
            throw ApiProblem::validation(['password' => $errors]);
        }
        DB::transaction(function () use ($identityToken, $user, $password) {
            $identityToken->forceFill(['used_at' => now()])->save();
            LocalIdentityToken::where('user_id', $user->id)->where('purpose', 'password_reset')->whereNull('used_at')->whereKeyNot($identityToken->id)->update(['used_at' => now()]);
            LocalCredential::where('user_id', $user->id)->update(['password_hash' => PasswordPolicy::hash($password), 'password_changed_at' => now(), 'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => now()]);
            $revoked = AuthSession::active()->where('user_id', $user->id)->update(['revoked_at' => now(), 'revoked_reason' => 'password_reset']);
            AuditLogger::record('identity.local_password_reset', 'user', $user->id, ['sessionsRevoked' => $revoked], null, context: ['object_name' => $user->name]);
        });
    }

    public function revokeToken(string $token, string $reason): void
    {
        AuthSession::where('token_hash', hash('sha256', $token))->where('method', 'local')->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    public function logoutCookie(string $token): ?User
    {
        $session = AuthSession::with('user')->where('token_hash', hash('sha256', $token))->where('method', 'local')->whereNull('revoked_at')->first();
        if (! $session) {
            return null;
        }
        $session->forceFill(['revoked_at' => now(), 'revoked_reason' => 'logout'])->save();

        return $session->user;
    }

    public function revokeAll(User $user, string $reason): int
    {
        return AuthSession::active()->where('user_id', $user->id)->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    private function issueLink(User $user, string $purpose, ?User $actor, string $path): bool
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return DB::transaction(function () use ($user, $purpose, $actor, $path, $raw) {
            LocalIdentityToken::where('user_id', $user->id)->where('purpose', $purpose)->whereNull('used_at')->update(['used_at' => now()]);
            LocalIdentityToken::create(['user_id' => $user->id, 'purpose' => $purpose, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addHour(), 'created_by' => $actor?->id]);
            $url = rtrim((string) config('app.frontend_url', config('app.url')), '/').$path.rawurlencode($raw);
            $event = $purpose === 'invitation' ? 'identity.invitation' : 'identity.password_reset';
            $subject = $purpose === 'invitation' ? 'Ative sua conta BPM TMSA' : 'Redefina sua senha BPM TMSA';
            $message = $this->notifier->localIdentityLink($user, $event, $subject, $url);

            return $message->state === NotificationMessage::QUEUED;
        });
    }

    private function validIdentityToken(string $token, string $purpose): LocalIdentityToken
    {
        $record = LocalIdentityToken::with('user')->where('token_hash', hash('sha256', $token))->where('purpose', $purpose)->whereNull('used_at')->where('expires_at', '>', now())->first();
        if (! $record || $record->user->auth_source !== 'local' || ($purpose === 'invitation' && $record->user->is_active)) {
            throw new ApiProblem(422, 'Link inválido', 'Este link expirou ou já foi utilizado. Solicite um novo link ao administrador.', 'identity_link_invalid');
        }

        return $record;
    }
}
