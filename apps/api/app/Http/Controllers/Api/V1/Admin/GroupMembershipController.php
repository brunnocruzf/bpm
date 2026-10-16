<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Authorization\Permissions;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\BpmGroup;
use App\Models\BpmGroupMembership;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Membros de grupos/raias (settings.manage). Três origens de acesso: vínculo manual (BPM TMSA), token do Entra e
 * sincronização/mapeamento. O vínculo manual é removido (DELETE members). O vínculo que vem do Entra ou do
 * mapeamento não pode ser apagado na origem (o Entra ID nunca é alterado), então é BLOQUEADO localmente
 * (PUT exclusions): o bloqueio sobrevive a logins e sincronizações até ser restaurado (DELETE exclusions).
 */
class GroupMembershipController extends ApiController
{
    private function authorizeAdmin(): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
    }

    public function index(Request $request, string $key)
    {
        $this->authorizeAdmin();
        $group = BpmGroup::where('key', $key)->first() ?? throw ApiProblem::notFound();
        $members = User::whereJsonContains('groups', $group->key)->orderBy('name')->paginate(min(100, max(1, (int) $request->query('perPage', 25))));
        $manual = BpmGroupMembership::with('group')->where('group_id', $group->id)->where('source', 'manual')->get()->keyBy('user_id');
        $excluded = BpmGroupMembership::with('user')->where('group_id', $group->id)->where('source', 'excluded')->orderByDesc('created_at')->get();

        return response()->json(['group' => ['key' => $group->key, 'name' => $group->name, 'description' => $group->description, 'entraGroupId' => $group->entra_group_id],
            'data' => $members->getCollection()->map(function (User $user) use ($group, $manual) {
                $manualLink = $manual->get($user->id);
                $source = $manualLink ? 'manual' : (in_array($group->key, $user->token_groups ?? [], true) ? 'entra_token' : (in_array($group->key, $user->directory_groups ?? [], true) ? 'entra_sync' : 'existing_mapping'));

                return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'authSource' => $user->auth_source, 'active' => $user->is_active,
                    'source' => $source, 'createdAt' => $manualLink?->created_at?->toIso8601String(), 'createdBy' => $manualLink?->created_by_name];
            })->values(),
            'excluded' => $excluded->map(fn (BpmGroupMembership $m) => ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email, 'authSource' => $m->user->auth_source,
                'createdAt' => $m->created_at?->toIso8601String(), 'createdBy' => $m->created_by_name])->values(),
            'meta' => ['currentPage' => $members->currentPage(), 'lastPage' => $members->lastPage(), 'total' => $members->total()]]);
    }

    public function store(Request $request, string $key)
    {
        $this->authorizeAdmin();
        $group = BpmGroup::where('key', $key)->first() ?? throw ApiProblem::notFound();
        $data = $request->validate(['userIds' => ['required', 'array', 'min:1', 'max:100'], 'userIds.*' => ['required', 'uuid', 'distinct', 'exists:users,id']]);
        $users = User::whereIn('id', $data['userIds'])->get()->keyBy('id');
        foreach ($users as $user) {
            if (! in_array($user->auth_source, [User::SOURCE_LOCAL, User::SOURCE_ENTRA], true)) {
                throw ApiProblem::validation(['userIds' => ['Contas de visitante e de emergência não podem receber vínculos de grupo.']]);
            }
            if ($user->is_active !== true) {
                throw ApiProblem::validation(['userIds' => ["A conta {$user->name} está inativa e não pode receber novas atribuições."]]);
            }
            if (BpmGroupMembership::where('group_id', $group->id)->where('user_id', $user->id)->where('source', 'manual')->exists()) {
                throw ApiProblem::conflict("{$user->name} já possui vínculo manual neste grupo.", 'membership_exists');
            }
        }
        DB::transaction(function () use ($users, $group) {
            foreach ($users as $user) {
                // adicionar manualmente é uma decisão explícita do administrador: desfaz um bloqueio local anterior
                $this->clearExclusion($user, $group, 'manual_add');
                $membership = BpmGroupMembership::create(['group_id' => $group->id, 'user_id' => $user->id, 'source' => 'manual', 'created_by' => $this->user()->id, 'created_by_name' => $this->user()->name, 'created_at' => now()]);
                $user->forceFill(['manual_groups' => array_values(array_unique([...($user->manual_groups ?? []), $group->key]))]);
                $user->recomputeEffectiveGroups();
                $user->save();
                AuditLogger::record('identity.local_group_member_added', 'bpm_group_membership', (string) $membership->id, ['groupKey' => $group->key, 'source' => 'manual'], null, $this->user(), context: ['object_name' => $group->name.' — '.$user->name]);
            }
        });

        return response()->json(['added' => $users->count()], 201);
    }

    /** Remove o vínculo MANUAL. Acesso que vem do Entra/mapeamento é bloqueado por exclude(). */
    public function destroy(string $key, string $userId)
    {
        $this->authorizeAdmin();
        $group = BpmGroup::where('key', $key)->first() ?? throw ApiProblem::notFound();
        $user = User::find($userId) ?? throw ApiProblem::notFound();
        $link = BpmGroupMembership::where('group_id', $group->id)->where('user_id', $user->id)->where('source', 'manual')->first();
        if (! $link) {
            if (in_array($group->key, $user->groups ?? [], true)) {
                throw ApiProblem::conflict('Este acesso vem do Entra ou de um mapeamento e não é um vínculo manual. Use "Remover do grupo" para bloqueá-lo neste sistema (o Entra ID não é alterado).', 'membership_not_manual');
            }
            throw ApiProblem::notFound();
        }
        DB::transaction(fn () => $this->removeManualLink($group, $user, $link));

        return response()->noContent();
    }

    /**
     * Remove o usuário do grupo/raia neste sistema, qualquer que seja a origem do vínculo. Vínculos do Entra (token ou
     * sincronização) ou de mapeamento ficam registrados como bloqueio local (idempotente) e continuam valendo na origem.
     */
    public function exclude(string $key, string $userId)
    {
        $this->authorizeAdmin();
        $group = BpmGroup::where('key', $key)->first() ?? throw ApiProblem::notFound();
        $user = User::find($userId) ?? throw ApiProblem::notFound();
        if ($user->auth_source === User::SOURCE_SUPERADMIN) {
            throw ApiProblem::validation(['userId' => ['A conta de emergência (SuperAdmin) não participa de grupos.']]);
        }
        if (BpmGroupMembership::where('group_id', $group->id)->where('user_id', $user->id)->where('source', 'excluded')->exists()) {
            return response()->noContent();
        }
        $link = BpmGroupMembership::where('group_id', $group->id)->where('user_id', $user->id)->where('source', 'manual')->first();
        $derived = $this->derivedSources($user, $group);
        if (! $link && ! $derived) {
            throw ApiProblem::notFound('O usuário não é membro deste grupo.');
        }
        DB::transaction(function () use ($group, $user, $link, $derived) {
            if ($link) {
                $this->removeManualLink($group, $user, $link);
            }
            if (! $derived) {
                return; // só havia vínculo manual: nada a bloquear
            }
            // createOrFirst: dois PUT simultâneos não estouram a unicidade (group, user, source); só o primeiro audita
            $row = BpmGroupMembership::createOrFirst(['group_id' => $group->id, 'user_id' => $user->id, 'source' => 'excluded'], ['created_by' => $this->user()->id, 'created_by_name' => $this->user()->name, 'created_at' => now()]);
            if (! $row->wasRecentlyCreated) {
                return;
            }
            $user->forceFill(['excluded_groups' => array_values(array_unique([...($user->excluded_groups ?? []), $group->key]))]);
            $user->recomputeEffectiveGroups();
            $user->save();
            AuditLogger::record('identity.group_member_excluded', 'bpm_group_membership', (string) $row->id, ['groupKey' => $group->key, 'sources' => $derived, 'alsoRemovedManualLink' => (bool) $link], null, $this->user(), context: ['object_name' => $group->name.' — '.$user->name]);
        });

        return response()->noContent();
    }

    /** Desfaz o bloqueio local: o vínculo volta a valer se a origem (Entra/mapeamento) ainda o concede. */
    public function restore(string $key, string $userId)
    {
        $this->authorizeAdmin();
        $group = BpmGroup::where('key', $key)->first() ?? throw ApiProblem::notFound();
        $user = User::find($userId) ?? throw ApiProblem::notFound();
        if (! BpmGroupMembership::where('group_id', $group->id)->where('user_id', $user->id)->where('source', 'excluded')->exists()) {
            throw ApiProblem::notFound('Não há bloqueio deste usuário neste grupo.');
        }
        DB::transaction(function () use ($group, $user) {
            $this->clearExclusion($user, $group, 'restore');
            $user->save();
        });

        return response()->noContent();
    }

    /** @return list<string> origens que concedem o grupo, além do vínculo manual (token, sincronização ou legado) */
    private function derivedSources(User $user, BpmGroup $group): array
    {
        $sources = [];
        if (in_array($group->key, $user->token_groups ?? [], true)) {
            $sources[] = 'entra_token';
        }
        if (in_array($group->key, $user->directory_groups ?? [], true)) {
            $sources[] = 'entra_sync';
        }
        // legado: o grupo está em "groups" sem vir de origem rastreada nem de vínculo manual
        if (! $sources && ! in_array($group->key, $user->manual_groups ?? [], true) && in_array($group->key, $user->groups ?? [], true)) {
            $sources[] = 'existing_mapping';
        }

        return $sources;
    }

    private function removeManualLink(BpmGroup $group, User $user, BpmGroupMembership $link): void
    {
        $link->delete();
        $user->forceFill(['manual_groups' => array_values(array_diff($user->manual_groups ?? [], [$group->key]))]);
        $user->recomputeEffectiveGroups();
        $user->save();
        AuditLogger::record('identity.local_group_member_removed', 'bpm_group_membership', (string) $link->id, ['groupKey' => $group->key, 'source' => 'manual'], null, $this->user(), context: ['object_name' => $group->name.' — '.$user->name]);
    }

    /** Apaga o registro de bloqueio e recalcula os grupos (não salva: o chamador salva na mesma transação). */
    private function clearExclusion(User $user, BpmGroup $group, string $via): void
    {
        $row = BpmGroupMembership::where('group_id', $group->id)->where('user_id', $user->id)->where('source', 'excluded')->first();
        if (! $row) {
            return;
        }
        $row->delete();
        $user->forceFill(['excluded_groups' => array_values(array_diff($user->excluded_groups ?? [], [$group->key]))]);
        if (! $user->hasTrackedAccessSources()) {
            // sem origem rastreada o grupo só existia em "groups": devolve-o
            $user->groups = array_values(array_unique([...($user->groups ?? []), $group->key]));
        }
        $user->recomputeEffectiveGroups();
        AuditLogger::record('identity.group_member_restored', 'bpm_group_membership', (string) $row->id, ['groupKey' => $group->key, 'via' => $via], null, $this->user(), context: ['object_name' => $group->name.' — '.$user->name]);
    }
}
