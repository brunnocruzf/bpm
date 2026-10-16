<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditCatalog;
use App\Audit\AuditDiff;
use App\Auth\LocalUserAuth;
use App\Auth\PasswordPolicy;
use App\Authorization\Permissions;
use App\Http\Controllers\Api\V1\ApiController;
use App\Identity\ManagedUserDetails;
use App\Identity\UserPhotoService;
use App\Models\AuditEvent;
use App\Models\AuthSession;
use App\Models\BpmGroupMembership;
use App\Models\DirectoryRoleMapping;
use App\Models\LocalCredential;
use App\Models\LocalIdentityToken;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserManagementController extends ApiController
{
    /** Pendências de integração filtráveis na listagem. */
    private const ATTENTION = ['entra_disabled', 'guest', 'login_only', 'manager_pending', 'invitation_pending', 'invitation_expired', 'locked'];

    public function __construct(private readonly LocalUserAuth $local, private readonly ManagedUserDetails $details, private readonly UserPhotoService $photos) {}

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
        $request->validate(['q' => ['nullable', 'string', 'max:200'], 'source' => ['nullable', 'in:entra,local,demo,superadmin'], 'active' => ['nullable', 'boolean'], 'area' => ['nullable', 'string', 'max:200'], 'role' => ['nullable', 'in:'.implode(',', array_values((array) config('bpm.roles')))], 'group' => ['nullable', 'exists:bpm_groups,key'], 'category' => ['nullable', Rule::in([...User::CATEGORIES, 'none'])], 'attention' => ['nullable', Rule::in(self::ATTENTION)], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = User::query()->with('manager:id,name,email,username,auth_source');
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
        if ($attention = $request->query('attention')) {
            $this->applyAttention($query, (string) $attention);
        }
        if ($category = $request->query('category')) {
            $category === 'none' ? $query->whereNull('user_category') : $query->where('user_category', $category);
        }
        if ($group = $request->query('group')) {
            $query->whereJsonContains('groups', $group);
        }
        $page = $query->orderBy('name')->paginate(min(100, max(1, (int) $request->query('perPage', 25))));

        $states = $this->details->listStates($page->getCollection());
        $photoVersions = $this->photos->versions($page->getCollection());

        return response()->json(['data' => $page->getCollection()->map(fn (User $u) => $this->row($u, $states[$u->id] ?? null, $photoVersions))->values(), 'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(string $id)
    {
        $this->authorizeAdmin();
        $user = User::with('manager')->find($id) ?? throw ApiProblem::notFound();
        $credential = LocalCredential::where('user_id', $user->id)->first();
        $manual = BpmGroupMembership::with('group')->where('user_id', $user->id)->where('source', 'manual')->get();
        $events = AuditEvent::where('entity_type', 'user')->where('entity_id', $user->id)->orderByDesc('occurred_at')->paginate(20);

        return response()->json(['data' => [
            'identity' => ['photo' => ($pv = $this->photos->versions(collect([$user]))[$user->id] ?? null) ? ['version' => $pv] : null, 'id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'upn' => $user->upn, 'authSource' => $user->auth_source, 'entraOid' => $user->entra_oid, 'entraTid' => $user->entra_tid],
            'organization' => ['area' => $user->area, 'department' => $user->department, 'userType' => $user->user_type, 'userCategory' => $user->user_category, 'manager' => $user->manager?->name,
                'managerInfo' => $user->manager ? ['id' => $user->manager->id, 'name' => $user->manager->name, 'email' => $user->manager->email, 'authSource' => $user->manager->auth_source] : null,
                'managerPending' => (bool) $user->manager_entra_oid && ! $user->manager_user_id, 'directReports' => $user->directReports()->count()],
            'account' => ['disabledAt' => $user->disabled_at?->toIso8601String(), 'active' => $user->is_active, 'accountEnabled' => $user->account_enabled, 'disabledReason' => $user->disabled_reason, 'createdAt' => $user->created_at?->toIso8601String(), 'updatedAt' => $user->updated_at?->toIso8601String(), 'lastLoginAt' => $credential?->last_login_at?->toIso8601String(), 'lastSeenAt' => $user->last_seen_at?->toIso8601String(), 'lockedUntil' => $credential?->locked_until?->toIso8601String(), 'directorySyncedAt' => $user->directory_synced_at?->toIso8601String(), 'invitationPending' => $user->auth_source === 'local' && ! $user->is_active && LocalIdentityToken::where('user_id', $user->id)->where('purpose', 'invitation')->whereNull('used_at')->where('expires_at', '>', now())->exists()],
            'access' => ['roles' => $user->roles ?? [], 'roleSources' => ['token' => $user->token_roles ?? [], 'directoryMapping' => $user->directory_roles ?? [], 'manual' => $user->manual_roles ?? []], 'groups' => $user->groups ?? [], 'groupSources' => ['entraToken' => $user->token_groups ?? [], 'directoryMapping' => $user->directory_groups ?? [], 'manual' => $manual->map(fn ($m) => ['key' => $m->group->key, 'name' => $m->group->name, 'createdAt' => $m->created_at?->toIso8601String(), 'createdBy' => $m->created_by_name])->values()]],
            'integration' => ['source' => $user->auth_source, 'entra' => $this->details->entra($user), 'local' => $this->details->local($user)],
            'activity' => $this->activityPayload($events),
        ]]);
    }

    public function activity(Request $request, string $id)
    {
        $this->authorizeAdmin();
        $user = User::find($id) ?? throw ApiProblem::notFound();
        $events = AuditEvent::where('entity_type', 'user')->where('entity_id', $user->id)->orderByDesc('occurred_at')->paginate(20);

        return response()->json($this->activityPayload($events));
    }

    /** Contagens por faceta para os filtros da tela (settings.manage). */
    public function facets()
    {
        $this->authorizeAdmin();
        $count = fn (array $where) => User::where($where)->count();
        $attention = [];
        foreach (self::ATTENTION as $key) {
            $q = User::query();
            $this->applyAttention($q, $key);
            $attention[$key] = $q->count();
        }

        return response()->json(['data' => [
            'total' => User::count(),
            'source' => collect(['entra', 'local', 'demo', 'superadmin'])->mapWithKeys(fn ($s) => [$s => $count(['auth_source' => $s])])->all(),
            'active' => ['true' => $count(['is_active' => true]), 'false' => $count(['is_active' => false])],
            'category' => [...collect(User::CATEGORIES)->mapWithKeys(fn ($c) => [$c => $count(['user_category' => $c])])->all(), 'none' => User::whereNull('user_category')->count()],
            'attention' => $attention,
            'areas' => User::whereNotNull('area')->where('area', '!=', '')->distinct()->orderBy('area')->limit(200)->pluck('area')->values(),
            'roles' => collect((array) config('bpm.roles'))->values()->map(fn ($r) => ['key' => $r, 'label' => str($r)->after('Bpm.')->replace('.', ' ')->value()])->all(),
        ]]);
    }

    /**
     * @param  LengthAwarePaginator<int, AuditEvent>  $events
     * @return array<string,mixed>
     */
    private function activityPayload($events): array
    {
        return ['data' => $events->getCollection()->map(fn (AuditEvent $e) => ['action' => $e->action, 'label' => AuditCatalog::label($e->action), 'outcome' => $e->outcome, 'actor' => $e->actor_name, 'occurredAt' => $e->occurred_at->toIso8601String(), 'diff' => $e->diff])->values(), 'meta' => ['currentPage' => $events->currentPage(), 'lastPage' => $events->lastPage(), 'total' => $events->total()]];
    }

    /** @param Builder<User> $query */
    private function applyAttention($query, string $attention): void
    {
        $pending = fn ($t) => $t->where('purpose', 'invitation')->whereNull('used_at')->where('expires_at', '>', now());
        match ($attention) {
            'entra_disabled' => $query->where('auth_source', User::SOURCE_ENTRA)->where(fn ($w) => $w->where('account_enabled', false)->orWhereIn('disabled_reason', ['directory_disabled', 'directory_deleted'])),
            'guest' => $query->where('user_type', 'Guest'),
            'login_only' => $query->where('auth_source', User::SOURCE_ENTRA)->whereNull('directory_synced_at'),
            'manager_pending' => $query->whereNotNull('manager_entra_oid')->whereNull('manager_user_id'),
            'invitation_pending' => $query->where('auth_source', User::SOURCE_LOCAL)->where('is_active', false)->whereHas('invitationTokens', $pending),
            'invitation_expired' => $query->where('auth_source', User::SOURCE_LOCAL)->where('is_active', false)->whereDoesntHave('invitationTokens', $pending)->whereDoesntHave('localCredential', fn ($c) => $c->whereNotNull('activated_at')),
            'locked' => $query->where('auth_source', User::SOURCE_LOCAL)->whereHas('localCredential', fn ($c) => $c->where('locked_until', '>', now())),
            default => null,
        };
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
            'userCategory' => ['required', Rule::in(User::CATEGORIES)],
        ], ['userCategory.required' => 'Selecione o tipo de usuário.', 'userCategory.in' => 'Selecione o tipo de usuário.']);
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
            $user = User::create(['name' => trim($data['name']), 'email' => $email, 'username' => $username, 'area' => $data['area'] ?? null, 'department' => $data['department'] ?? null, 'user_category' => $data['userCategory'],
                'auth_source' => User::SOURCE_LOCAL, 'is_active' => false, 'roles' => [], 'groups' => [], 'manual_groups' => [], 'manual_roles' => [], 'token_roles' => [], 'token_groups' => [], 'directory_roles' => [], 'directory_groups' => []]);
            LocalCredential::create(['user_id' => $user->id, 'password_hash' => PasswordPolicy::hash(bin2hex(random_bytes(48))), 'password_changed_at' => now()]);
            $invitationQueued = $this->local->issueInvitation($user, $this->user());
            AuditLogger::record('identity.local_user_created', 'user', $user->id, ['authSource' => 'local', 'status' => 'invitation_pending', 'userCategory' => $data['userCategory']], null, $this->user(), context: ['object_name' => $user->name]);

            return $user;
        });

        return response()->json(['data' => $this->row($user), 'message' => $invitationQueued ? 'Conta criada sem acesso; o convite foi enfileirado pelo serviço de e-mail configurado.' : 'Conta criada pendente e sem acesso. Configure o serviço de e-mail e reenvie o convite quando estiver disponível.'], 201);
    }

    public function update(Request $request, string $id)
    {
        $this->authorizeAdmin();
        $user = User::find($id) ?? throw ApiProblem::notFound();
        $data = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'userCategory' => ['sometimes', Rule::in(User::CATEGORIES)],
        ], ['userCategory.in' => 'Selecione o tipo de usuário.']);
        if (! array_key_exists('active', $data) && ! array_key_exists('userCategory', $data)) {
            throw ApiProblem::validation(['active' => ['Informe a situação ou o tipo de usuário.']]);
        }
        if ($user->auth_source !== 'local') {
            throw ApiProblem::validation([array_key_exists('active', $data) ? 'active' : 'userCategory' => ['Contas Entra são gerenciadas pelo diretório.']]);
        }
        if (array_key_exists('userCategory', $data) && $user->user_category !== $data['userCategory']) {
            $before = ['userCategory' => $user->user_category];
            $user->forceFill(['user_category' => $data['userCategory']])->save();
            AuditLogger::record('identity.user_category_changed', 'user', $user->id, ['userCategory' => $user->user_category], null, $this->user(), diff: AuditDiff::between($before, ['userCategory' => $user->user_category]), context: ['object_name' => $user->name]);
        }
        if (! array_key_exists('active', $data)) {
            return response()->json(['data' => $this->row($user->fresh())]);
        }
        $active = (bool) $data['active'];
        if ($user->is_active === $active) {
            return response()->json(['data' => $this->row($user)]);
        }
        if ($active && ! LocalCredential::where('user_id', $user->id)->whereNotNull('activated_at')->exists()) {
            throw ApiProblem::conflict('Esta conta ainda não foi ativada pelo convite. Reenvie o convite para que a pessoa defina a senha.', 'invitation_activation_required');
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

    /**
     * @param  array{status:string, locked:bool}|null  $state
     * @param  array<string,string>|null  $photoVersions
     */
    private function row(User $user, ?array $state = null, ?array $photoVersions = null): array
    {
        $state ??= $this->details->listStates(collect([$user]))[$user->id] ?? null;
        $photoVersions ??= $this->photos->versions(collect([$user]));

        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'upn' => $user->upn, 'username' => $user->username, 'area' => $user->area,
            'department' => $user->department, 'userCategory' => $user->user_category, 'authSource' => $user->auth_source, 'isActive' => $user->is_active, 'roles' => $user->roles ?? [], 'groups' => $user->groups ?? [],
            'manager' => $user->manager ? ['id' => $user->manager->id, 'name' => $user->manager->name, 'email' => $user->manager->email, 'username' => $user->manager->username, 'authSource' => $user->manager->auth_source] : null,
            'managerPending' => (bool) $user->manager_entra_oid && ! $user->manager_user_id,
            'invitationPending' => $state !== null && ! $user->is_active && $state['status'] === ManagedUserDetails::INVITATION_PENDING,
            'photo' => isset($photoVersions[$user->id]) ? ['version' => $photoVersions[$user->id]] : null,
            'invitationStatus' => $state['status'] ?? null, 'locked' => $state['locked'] ?? false,
            'userType' => $user->user_type, 'accountEnabled' => $user->account_enabled, 'disabledReason' => $user->disabled_reason,
            'lastSeenAt' => $user->last_seen_at?->toIso8601String(), 'directorySyncedAt' => $user->directory_synced_at?->toIso8601String()];
    }
}
