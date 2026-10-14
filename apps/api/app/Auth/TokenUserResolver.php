<?php

namespace App\Auth;

use App\Audit\AuditContext;
use App\Audit\AuditDiff;
use App\Models\BpmGroup;
use App\Models\User;
use App\Support\AuditLogger;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Resolve o usuário a partir do bearer token. O frontend nunca é fonte de
 * verdade para papéis ou grupos: tudo vem do token validado no servidor e dos
 * mapeamentos explícitos do diretório (união; nada chega pelo browser).
 *
 * Métodos (ADR-0015), escolhidos pelo formato do token e sempre restritos à origem da conta:
 *  - "dev:<username>"  → visitante/demonstração (somente usuários fictícios; nunca em produção);
 *  - "bpmsa_..."       → sessão SuperAdmin emitida pelo Laravel (SuperAdminAuth);
 *  - demais            → access token do Entra ID (JWKS).
 */
class TokenUserResolver
{
    public function __construct(private readonly EntraTokenValidator $validator, private readonly SuperAdminAuth $superAdmin, private readonly LocalUserAuth $local) {}

    public static function assertSafeConfiguration(): void
    {
        AuthMethods::assertSafeConfiguration();
    }

    public function __invoke(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        try {
            [$method, $user] = match (true) {
                str_starts_with($token, 'dev:') => [AuthMethods::DEMO, $this->fromDevToken($token)],
                str_starts_with($token, SuperAdminAuth::TOKEN_PREFIX) => [AuthMethods::SUPERADMIN, $this->superAdmin->resolve($token, $request)],
                str_starts_with($token, LocalUserAuth::TOKEN_PREFIX) => ['local', $this->local->resolve($token, $request)],
                default => [AuthMethods::ENTRA, $this->fromEntraToken($token)],
            };
        } catch (Throwable $e) {
            Log::notice('Token rejeitado', ['reason' => $e->getMessage()]);
            if (! str_starts_with($token, 'dev:') && ! str_starts_with($token, SuperAdminAuth::TOKEN_PREFIX)) {
                $this->auditRejectedEntraToken($token, $e);
            }

            return null;
        }
        if (! $user) {
            return null;
        }
        if (! $request->attributes->has('bpm.auth')) {
            $request->attributes->set('bpm.auth', ['method' => $method]);
        }

        // is_active pode ser null num usuário recém-criado (default do banco não carregado): só bloqueia se false.
        if ($user->is_active === false) {
            if (Cache::add('bpm:login-blocked:'.$user->id, 1, 3600)) {
                AuditLogger::record('auth.login_blocked', 'user', $user->id, ['reason' => $user->disabled_reason ?? 'inactive'], null, $user, outcome: 'blocked', context: ['object_name' => $user->name]);
            }

            return null;
        }
        if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(5))) {
            // Sem sessão no servidor (bearer por requisição): considera novo acesso após 30 min de inatividade.
            if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(30))) {
                AuditLogger::record('auth.login', 'user', $user->id, ['method' => $method], null, $user, context: ['object_name' => $user->name]);
            }
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $user;
    }

    /** Motivo estável da recusa de um access token Entra (exibido no log do Entra ID com explicação). */
    public static function rejectionReason(Throwable $e): string
    {
        $msg = $e->getMessage();

        return match (true) {
            $e instanceof ExpiredException => 'token_expired',
            $e instanceof BeforeValidException => 'not_yet_valid',
            $e instanceof SignatureInvalidException => 'signature_invalid',
            str_contains($msg, 'não configurado') => 'not_configured',
            str_contains($msg, 'Tenant') => 'tenant_not_allowed',
            str_contains($msg, 'Issuer') => 'issuer_mismatch',
            str_contains($msg, 'Audience') => 'audience_mismatch',
            str_contains($msg, 'Scope') => 'scope_missing',
            str_contains($msg, 'identidade de usuário') => 'no_user_identity',
            str_contains($msg, 'JWKS') => 'jwks_unavailable',
            str_contains($msg, '"kid"') || str_contains($msg, 'kid') => 'unknown_signing_key',
            default => 'token_malformed',
        };
    }

    /**
     * Auditoria da recusa (sem o token): motivo + claims públicas NÃO verificadas (tid/aud/iss/ver),
     * para diagnosticar configuração. Uma vez por token e no máximo 30 por minuto.
     */
    private function auditRejectedEntraToken(string $token, Throwable $e): void
    {
        try {
            if (! Cache::add('bpm:token-rejected:'.substr(hash('sha256', $token), 0, 32), 1, 600)
                || ! RateLimiter::attempt('bpm:token-rejected-audit', 30, fn () => true, 60)) {
                return;
            }
            $parts = explode('.', $token);
            $payload = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true) : null;
            $claims = is_array($payload) ? array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 120) : (is_array($v) ? mb_substr(implode(',', array_filter($v, 'is_scalar')), 0, 120) : null),
                array_intersect_key($payload, array_flip(['tid', 'aud', 'iss', 'ver', 'scp']))) : [];
            AuditContext::as('tentativa de login (não autenticado)', fn () => AuditLogger::record('auth.token_rejected', 'auth', null,
                array_filter(['method' => AuthMethods::ENTRA, 'reason' => self::rejectionReason($e), 'unverifiedClaims' => $claims ?: null]),
                outcome: 'failure', context: ['object_name' => 'Access token Entra ID']));
        } catch (Throwable) {
            // auditoria da recusa nunca interfere na resposta 401
        }
    }

    private function fromDevToken(string $token): ?User
    {
        if (! AuthMethods::demoEnabled()) {
            return null;
        }

        // somente contas fictícias: nunca o SuperAdmin nem usuários do Entra
        return User::where('username', substr($token, 4))->whereNull('entra_oid')
            ->where(fn ($q) => $q->whereNull('auth_source')->orWhere('auth_source', User::SOURCE_DEMO))->first();
    }

    private function fromEntraToken(string $token): User
    {
        $claims = $this->validator->validate($token);

        $groupIds = array_map('strval', (array) ($claims['groups'] ?? []));
        $groups = $groupIds ? BpmGroup::whereIn('entra_group_id', $groupIds)->pluck('key')->all() : [];
        $roles = array_values(array_filter((array) ($claims['roles'] ?? []), fn ($r) => str_starts_with((string) $r, 'Bpm.')));
        $areaClaim = (string) config('bpm.auth.entra.area_claim', 'department');

        $user = User::firstOrNew(['entra_oid' => (string) $claims['oid'], 'entra_tid' => (string) $claims['tid']]);
        $before = ['roles' => $user->roles ?? [], 'groups' => $user->groups ?? []];
        $user->fill([
            'name' => (string) ($claims['name'] ?? 'Usuário'),
            'email' => isset($claims['preferred_username']) ? (string) $claims['preferred_username'] : $user->email,
            'upn' => isset($claims['preferred_username']) ? (string) $claims['preferred_username'] : $user->upn,
            'area' => isset($claims[$areaClaim]) ? (string) $claims[$areaClaim] : $user->area,
            'token_roles' => $roles,
            'token_groups' => $groups,
        ]);
        if (! $user->exists) {
            $user->is_active = true;
            $user->auth_source = User::SOURCE_ENTRA;
            $user->directory_roles ??= [];
            $user->directory_groups ??= [];
        }
        $user->recomputeEffectiveAccess();
        $user->save();

        $after = ['roles' => $user->roles, 'groups' => $user->groups];
        if ($user->wasRecentlyCreated === false && $before !== $after) {
            AuditLogger::record('auth.roles_changed', 'user', $user->id, ['source' => 'token'], null, $user, diff: AuditDiff::between($before, $after), context: ['object_name' => $user->name]);
        }

        return $user;
    }
}
