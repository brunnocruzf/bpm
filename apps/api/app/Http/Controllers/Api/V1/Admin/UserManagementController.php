<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditCatalog;
use App\Audit\AuditDiff;
use App\Auth\LocalUserAuth;
use App\Auth\PasswordPolicy;
use App\Authorization\Permissions;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AuditEvent;
use App\Models\AuthSession;
use App\Models\BpmGroupMembership;
use App\Models\DirectoryRoleMapping;
use App\Models\LocalCredential;
use App\Models\LocalIdentityToken;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserManagementController extends ApiController
{
    public function __construct(private readonly LocalUserAuth $local) {}

    private function authorizeAdmin(): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin();
        if ($request->query->has('active')) {
            $active = strtolower((string) $request->query('active'));
            if (in_array($active, ['true', 'false', '1', '0'], true)) {
                $request->query->set('active', in_array($active, ['true', '1'], true));
            }
        }
        $request->validate(['q' => ['nullable', 'string', 'max:200'], 'source' => ['nullable', 'in:entra,local,demo,superadmin'], 'active' => ['nullable', 'boolean'], 'area' => ['nullable', 'string', 'max:200'], 'role' => ['nullable', 'in:'.implode(',', array_values((array) config('bpm.roles')))], 'group' => ['nullable', 'exists:bpm_groups,key'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = User::query();
        if ($q = trim((string) $request->query('q', ''))) {
            $like = '%'.mb_strtolower($q).'%';
            $query->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like])->orWhereRaw('LOWER(upn) LIKE ?', [$like])->orWhereRaw('LOWER(username) LIKE ?', [$like]));
        }
        if ($source = $request->query('source')) {
            $query->where('auth_source', $source);
        }
        if ($request->query->has('active')) {
            $query->where('is_active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($area = $request->query('area')) {
            $query->where('area', $area);
        }
        if ($role = $request->query('role')) {
            $query->whereJsonContains('roles', $role);
        }
        if ($group = $request->query('group')) {
            $query->whereJsonContains('groups', $group);
        }
        $page = $query->orderBy('name')->paginate(min(100, max(1, (int) $request->query('perPage', 25))));

        return response()->json(['data' => $page->getCollection()->map(fn (User $u) => $this->row($u))->values(), 'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(string $id)
    {
        $this->authorizeAdmin();
        $user = User::find($id) ?? throw ApiProblem::notFound();
        $credential = LocalCredential::where('user_id', $user->id)->first();
        $manual = BpmGroupMembership::with('group')->where('user_id', $user->id)->where('source', 'manual')->get();
        $events = AuditEvent::where('entity_type', 'user')->where('entity_id', $user->id)->orderByDesc('occurred_at')->paginate(20);

        return response()->json(['data' => [
            'identity' => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'upn' => $user->upn, 'authSource' => $user->auth_source, 'entraOid' => $user->entra_oid, 'entraTid' => $user->entra_tid],
            'organization' => ['area' => $user->area, 'department' => $user->department, 'userType' => $user->user_type],
            'account' => ['active' => $user->is_active, 'accountEnabled' => $user->account_enabled, 'disabledReason' => $user->disabled_reason, 'createdAt' => $user->created_at?->toIso8601String(), 'updatedAt' => $user->updated_at?->toIso8601String(), 'lastLoginAt' => $credential?->last_login_at?->toIso8601String(), 'lastSeenAt' => $user->last_seen_at?->toIso8601String(), 'lockedUntil' => $credential?->locked_until?->toIso8601String(), 'directorySyncedAt' => $user->directory_synced_at?->toIso8601String(), 'invitationPending' => $user->auth_source === 'local' && ! $user->is_active && LocalIdentityToken::where('user_id', $user->id)->where('purpose', 'invitation')->whereNull('used_at')->where('expires_at', '>', now())->exists()],
            'access' => ['roles' => $user->roles ?? [], 'roleSources' => ['token' => $user->token_roles ?? [], 'directoryMapping' => $user->directory_roles ?? [], 'manual' => $user->manual_roles ?? []], 'groups' => $user->groups ?? [], 'groupSources' => ['entraToken' => $user->token_groups ?? [], 'directoryMapping' => $user->directory_groups ?? [], 'manual' => $manual->map(fn ($m) => ['key' => $m->group->key, 'name' => $m->group->name, 'createdAt' => $m->created_at?->toIso8601String(), 'createdBy' => $m->created_by_name])->values()]],
            'activity' => ['data' => $events->getCollection()->map(fn (AuditEvent $e) => ['action' => $e->action, 'label' => AuditCatalog::label($e->action), 'outcome' => $e->outcome, 'actor' => $e->actor_name, 'occurredAt' => $e->occurred_at->toIso8601String(), 'diff' => $e->diff])->values(), 'meta' => ['currentPage' => $events->currentPage(), 'lastPage' => $events->lastPage(), 'total' => $events->total()]],
        ]]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:200'],
            'username' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'area' => ['nullable', 'string', 'max:200'],
            'department' => ['nullable', 'string', 'max:200'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ApiProblem::validation(['email' => ['Este e-mail já está associado a uma identidade existente. Não vinculamos contas automaticamente.']]);
        }
        $username = mb_strtolower(trim($data['username']));
        if (User::whereRaw('LOWER(username) = ?', [$username])->exists()) {
            throw ApiProblem::validation(['username' => ['Este usuário já está em uso.']]);
        }
        $invitationQueued = false;
        $user = DB::transaction(function () use ($data, $email, $username, &$invitationQueued) {
            $user = User::create(['name' => trim($data['name']), 'email' => $email, 'username' => $username, 'area' => $data['area'] ?? null, 'department' => $data['department'] ?? null,
                'auth_source' => User::SOURCE_LOCAL, 'is_active' => false, 'roles' => [], 'groups' => [], 'manual_groups' => [], 'manual_roles' => [], 'token_roles' => [], 'token_groups' => [], 'directory_roles' => [], 'directory_groups' => []]);
            LocalCredential::create(['user_id' => $user->id, 'password_hash' => PasswordPolicy::hash(bin2hex(random_bytes(48))), 'password_changed_at' => now()]);
            $invitationQueued = $this->local->issueInvitation($user, $this->user());
            AuditLogger::record('identity.local_user_created', 'user', $user->id, ['authSource' => 'local', 'status' => 'invitation_pending'], null, $this->user(), context: ['object_name' => $user->name]);

            return $user;
        });

        return response()->json(['data' => $this->row($user), 'message' => $invitationQueued ? 'Conta criada sem acesso; o convite foi enfileirado pelo serviço de e-mail configurado.' : 'Conta criada pendente e sem acesso. Configure o serviço de e-mail e reenvie o convite quando estiver disponível.'], 201);
    }

    public function update(Request $request, string $id)
    {
        $this->authorizeAdmin();
        $user = User::find($id) ?? throw ApiProblem::notFound();
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $active = (bool) $data['active'];
        if ($user->auth_source !== 'local') {
            throw ApiProblem::validation(['active' => ['Contas Entra são gerenciadas pelo diretório.']]);
        }
        if ($user->is_active === $active) {
            return response()->json(['data' => $this->row($user)]);
        }
        if ($active && ! LocalIdentityToken::where('user_id', $user->id)->where('purpose', 'invitation')->whereNotNull('used_at')->exists()) {
            throw ApiProblem::conflict('Esta conta ainda n\u00e3o foi ativada pelo convite. Reenvie o convite para que a pessoa defina a senha.', 'invitation_activation_required');
        }
        DB::transaction(function () use ($user, $active) {
            $before = ['active' => $user->is_active, 'disabledReason' => $user->disabled_reason];
            $user->forceFill(['is_active' => $active, 'disabled_at' => $active ? null : now(), 'disabled_reason' => $active ? null : 'admin'])->save();
            if (! $active) {
                $this->local->revokeAll($user, 'account_disabled');
            }
            AuditLogger::record($active ? 'identity.local_user_activated' : 'identity.local_user_disabled', 'user', $user->id, ['sessionsRevoked' => $active ? 0 : AuthSession::where('user_id', $user->id)->where('revoked_reason', 'account_disabled')->count()], null, $this->user(), diff: AuditDiff::between($before, ['active' => $user->is_active, 'disabledReason' => $user->disabled_reason]), context: ['object_name' => $user->name]);
        });

        return response()->json(['data' => $this->row($user->fresh())]);
    }

    public function resendInvitation(string $id)
    {
        $this->authorizeAdmin();
        $user = User::find($id) ?? throw ApiProblem::notFound();
        if ($user->auth_source !== 'local' || $user->is_active) {
            throw ApiProblem::conflict('Somente contas locais pendentes podem receber novo convite.', 'invitation_not_pending');
        }
        $this->local->issueInvitation($user, $this->user());

        return response()->json(['message' => 'Se o e-mail estiver configurado, um novo convite foi enfileirado.']);
    }

    public function roles(Request $request)
    {
        $this->authorizeAdmin();
        $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(1, (int) $request->query('perPage', 25)));
        $configured = (array) config('bpm.roles');
        $mappings = DirectoryRoleMapping::where('enabled', true)->get();
        $data = collect($configured)->map(function ($technical, $alias) use ($mappings, $page, $perPage) {
            $userQuery = User::whereJsonContains('roles', $technical);
            $userCount = (clone $userQuery)->count();
            $users = $userQuery->orderBy('name')->forPage($page, $perPage)->get();
            $permissions = collect((array) config('bpm.permissions'))->filter(fn ($roles) => in_array($alias, (array) $roles, true))->keys()->values();

            return ['key' => $technical, 'alias' => $alias, 'name' => str($technical)->after('Bpm.')->replace('.', ' ')->value(), 'active' => true,
                'permissions' => $permissions->map(fn ($permission) => ['key' => $permission, 'source' => 'Gate/policy do produto'])->all(),
                'userCount' => $userCount, 'users' => $users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'authSource' => $u->auth_source,
                    'source' => in_array($technical, $u->token_roles ?? [], true) ? 'token' : (in_array($technical, $u->directory_roles ?? [], true) ? 'mapeamento Entra' : (in_array($technical, $u->manual_roles ?? [], true) ? 'manual — BPM TMSA' : 'papel efetivo')),
                    'manual' => in_array($technical, $u->manual_roles ?? [], true)])->values(),
                'directoryMappings' => $mappings->filter(fn ($m) => in_array($technical, (array) $m->tmsa_roles, true))->map(fn ($m) => ['group' => $m->entra_group_name, 'entraGroupId' => $m->entra_group_id])->values()];
        })->values();

        return response()->json(['data' => $data, 'meta' => ['currentPage' => $page, 'perPage' => $perPage]]);
    }

    private function row(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'upn' => $user->upn, 'username' => $user->username, 'area' => $user->area,
            'department' => $user->department, 'authSource' => $user->auth_source, 'isActive' => $user->is_active, 'roles' => $user->roles ?? [], 'groups' => $user->groups ?? [],
            'invitationPending' => $user->auth_source === 'local' && ! $user->is_active && LocalIdentityToken::where('user_id', $user->id)->where('purpose', 'invitation')->whereNull('used_at')->where('expires_at', '>', now())->exists(),
            'lastSeenAt' => $user->last_seen_at?->toIso8601String(), 'directorySyncedAt' => $user->directory_synced_at?->toIso8601String()];
    }
}
