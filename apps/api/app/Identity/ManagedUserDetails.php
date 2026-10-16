<?php

namespace App\Identity;

use App\Models\AuthSession;
use App\Models\DirectoryMembership;
use App\Models\DirectoryRoleMapping;
use App\Models\LocalCredential;
use App\Models\LocalIdentityToken;
use App\Models\NotificationMessage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Dados de integração exibidos na administração de usuários. Lê somente o que já está no banco (sincronização,
 * credencial local, convites, e-mail): nenhuma chamada ao Microsoft Graph. Nunca expõe IP, token, hash ou cursor.
 */
class ManagedUserDetails
{
    public const INVITATION_NONE = 'none';

    public const INVITATION_PENDING = 'pending';

    public const INVITATION_EXPIRED = 'expired';

    public const INVITATION_ACCEPTED = 'accepted';

    /**
     * Situação do convite por usuário local, em consultas em lote (sem N+1).
     *
     * @param  Collection<int, User>  $users
     * @return array<string, array{status:string, locked:bool}>
     */
    public function listStates(Collection $users): array
    {
        $ids = $users->where('auth_source', User::SOURCE_LOCAL)->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $credentials = LocalCredential::whereIn('user_id', $ids)->get()->keyBy('user_id');
        $tokens = LocalIdentityToken::whereIn('user_id', $ids)->where('purpose', 'invitation')->get(['user_id', 'expires_at', 'used_at'])->groupBy('user_id');
        $out = [];
        foreach ($ids as $id) {
            $credential = $credentials->get($id);
            $out[$id] = [
                'status' => $this->invitationStatus($credential, $tokens->get($id, collect())),
                'locked' => (bool) $credential?->isLocked(),
            ];
        }

        return $out;
    }

    /** @param Collection<int, LocalIdentityToken> $tokens */
    public function invitationStatus(?LocalCredential $credential, Collection $tokens): string
    {
        if ($tokens->contains(fn (LocalIdentityToken $t) => $t->used_at === null && $t->expires_at->isFuture())) {
            return self::INVITATION_PENDING;
        }
        if ($credential?->activated_at) {
            return self::INVITATION_ACCEPTED;
        }

        return $tokens->isEmpty() ? self::INVITATION_NONE : self::INVITATION_EXPIRED;
    }

    /** @return array<string,mixed>|null */
    public function entra(User $user): ?array
    {
        if ($user->auth_source !== User::SOURCE_ENTRA) {
            return null;
        }
        $memberships = DirectoryMembership::with('group')->where('user_id', $user->id)->whereNull('removed_at')->get()->filter(fn ($m) => $m->group && $m->group->removed_at === null);
        $mappings = DirectoryRoleMapping::where('enabled', true)->whereIn('entra_group_id', $memberships->pluck('group.object_id')->all())->get()->groupBy('entra_group_id');
        $overview = app(DirectorySyncService::class)->overview();

        return [
            'oid' => $user->entra_oid, 'tid' => $user->entra_tid, 'upn' => $user->upn,
            'userType' => $user->user_type, 'accountEnabled' => $user->account_enabled,
            'directorySyncedAt' => $user->directory_synced_at?->toIso8601String(),
            'importedBySync' => $user->directory_synced_at !== null,
            'groups' => $memberships->map(fn (DirectoryMembership $m) => [
                'objectId' => $m->group->object_id, 'name' => $m->group->display_name, 'mail' => $m->group->mail,
                'syncedAt' => $m->synced_at->toIso8601String(),
                'grants' => $mappings->get($m->group->object_id, collect())->map(fn ($map) => ['groupKey' => $map->tmsa_group_key, 'roles' => $map->tmsa_roles ?? [], 'privileged' => (bool) $map->privileged])->values(),
            ])->values(),
            'sync' => ['lastSuccessAt' => $overview['lastSuccessAt'], 'nextRunAt' => $overview['nextRunAt'], 'alerts' => $overview['alerts']],
        ];
    }

    /** @return array<string,mixed>|null */
    public function local(User $user): ?array
    {
        if ($user->auth_source !== User::SOURCE_LOCAL) {
            return null;
        }
        $credential = LocalCredential::where('user_id', $user->id)->first();
        $tokens = LocalIdentityToken::where('user_id', $user->id)->where('purpose', 'invitation')->orderByDesc('created_at')->get();
        $latest = $tokens->first();
        $status = $this->invitationStatus($credential, $tokens);
        $message = NotificationMessage::where('recipient_user_id', $user->id)->where('event', 'identity.invitation')->latest('created_at')->first();
        $idle = (int) config('bpm.auth.local.idle_minutes', 30);
        $sessions = AuthSession::active()->where('user_id', $user->id)->where('method', 'local')->get(['last_used_at', 'created_at'])
            ->filter(fn (AuthSession $s) => ($s->last_used_at ?? $s->created_at)->gte(now()->subMinutes($idle)));
        $accepted = $credential?->activated_at;

        return [
            'invitation' => [
                'status' => $status,
                'sentAt' => $latest?->created_at?->toIso8601String(),
                'expiresAt' => $latest?->expires_at?->toIso8601String(),
                'acceptedAt' => $accepted?->toIso8601String(),
                'invitedBy' => $latest?->created_by ? User::whereKey($latest->created_by)->value('name') : null,
                'delivery' => $message ? ['state' => $message->state, 'sentAt' => $message->sent_at?->toIso8601String(), 'failedAt' => $message->failed_at?->toIso8601String(), 'errorCode' => $message->error_code] : null,
            ],
            'credential' => [
                'lastLoginAt' => $credential?->last_login_at?->toIso8601String(), 'failedAttempts' => $credential ? $credential->failed_attempts : 0,
                'lockedUntil' => $credential?->isLocked() ? $credential->locked_until?->toIso8601String() : null,
                'passwordChangedAt' => $accepted ? $credential->password_changed_at->toIso8601String() : null,
            ],
            'sessions' => ['active' => $sessions->count(), 'lastUsedAt' => $sessions->map(fn ($s) => $s->last_used_at ?? $s->created_at)->max()?->toIso8601String()],
        ];
    }
}
