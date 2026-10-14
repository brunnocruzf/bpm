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

        return response()->json(['group' => ['key' => $group->key, 'name' => $group->name, 'description' => $group->description, 'entraGroupId' => $group->entra_group_id],
            'data' => $members->getCollection()->map(function (User $user) use ($group, $manual) {
                $manualLink = $manual->get($user->id);
                $source = $manualLink ? 'manual' : (in_array($group->key, $user->token_groups ?? [], true) ? 'entra_token' : (in_array($group->key, $user->directory_groups ?? [], true) ? 'entra_sync' : 'existing_mapping'));

                return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'authSource' => $user->auth_source, 'active' => $user->is_active,
                    'source' => $source, 'createdAt' => $manualLink?->created_at?->toIso8601String(), 'createdBy' => $manualLink?->created_by_name];
            })->values(),
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
                BpmGroupMembership::create(['group_id' => $group->id, 'user_id' => $user->id, 'source' => 'manual', 'created_by' => $this->user()->id, 'created_by_name' => $this->user()->name, 'created_at' => now()]);
                $manual = array_values(array_unique([...($user->manual_groups ?? []), $group->key]));
                $user->forceFill(['manual_groups' => $manual, 'groups' => array_values(array_unique([...($user->token_groups ?? []), ...($user->directory_groups ?? []), ...$manual]))])->save();
                AuditLogger::record('identity.local_group_member_added', 'bpm_group_membership', $group->id.':'.$user->id, ['groupKey' => $group->key, 'source' => 'manual'], null, $this->user(), context: ['object_name' => $group->name.' — '.$user->name]);
            }
        });

        return response()->json(['added' => $users->count()], 201);
    }

    public function destroy(string $key, string $userId)
    {
        $this->authorizeAdmin();
        $group = BpmGroup::where('key', $key)->first() ?? throw ApiProblem::notFound();
        $user = User::find($userId) ?? throw ApiProblem::notFound();
        $link = BpmGroupMembership::where('group_id', $group->id)->where('user_id', $user->id)->where('source', 'manual')->first();
        if (! $link) {
            if (in_array($group->key, $user->groups ?? [], true)) {
                throw ApiProblem::conflict('Este acesso vem do Entra ou de um mapeamento e não pode ser removido manualmente aqui.', 'membership_not_manual');
            }
            throw ApiProblem::notFound();
        }
        DB::transaction(function () use ($group, $user, $link) {
            $link->delete();
            $manual = array_values(array_diff($user->manual_groups ?? [], [$group->key]));
            $user->forceFill(['manual_groups' => $manual, 'groups' => array_values(array_unique([...($user->token_groups ?? []), ...($user->directory_groups ?? []), ...$manual]))])->save();
            AuditLogger::record('identity.local_group_member_removed', 'bpm_group_membership', $group->id.':'.$user->id, ['groupKey' => $group->key, 'source' => 'manual'], null, $this->user(), context: ['object_name' => $group->name.' — '.$user->name]);
        });

        return response()->noContent();
    }
}
