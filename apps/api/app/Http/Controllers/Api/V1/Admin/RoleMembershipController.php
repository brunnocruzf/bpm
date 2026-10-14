<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Authorization\Permissions;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RoleMembershipController extends ApiController
{
    private function authorizeAdmin(): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
    }

    public function store(Request $request, string $roleKey)
    {
        $this->authorizeAdmin();
        $this->assertConfiguredRole($roleKey);
        $data = $request->validate([
            'userIds' => ['required', 'array', 'min:1', 'max:100'],
            'userIds.*' => ['required', 'uuid', 'distinct', 'exists:users,id'],
        ]);
        $actor = $this->user();
        $users = User::whereIn('id', $data['userIds'])->get();

        foreach ($users as $user) {
            if ($user->id === $actor->id) {
                throw ApiProblem::conflict('Nao e permitido atribuir um papel a propria conta.', 'self_role_assignment');
            }
            if (! in_array($user->auth_source, [User::SOURCE_LOCAL, User::SOURCE_ENTRA], true)) {
                throw ApiProblem::validation(['userIds' => ['Contas de visitante e de emergencia nao podem receber papeis manuais.']]);
            }
            if ($user->is_active !== true) {
                throw ApiProblem::validation(['userIds' => ["A conta {$user->name} esta inativa e nao pode receber novas atribuicoes."]]);
            }
            if (in_array($roleKey, $user->roles ?? [], true)) {
                throw ApiProblem::conflict("{$user->name} ja possui este papel por outra origem ou atribuicao manual.", 'role_already_effective');
            }
        }

        DB::transaction(function () use ($users, $roleKey, $actor) {
            foreach ($users as $user) {
                $manualRoles = array_values(array_unique([...($user->manual_roles ?? []), $roleKey]));
                $user->forceFill(['manual_roles' => $manualRoles]);
                $user->recomputeEffectiveAccess();
                $user->save();
                AuditLogger::record('identity.manual_role_assigned', 'user', $user->id, ['role' => $roleKey, 'source' => 'manual'], null, $actor, context: ['object_name' => $user->name.' — '.$roleKey]);
            }
        });

        return response()->json(['added' => $users->count()], 201);
    }

    public function destroy(string $roleKey, string $userId)
    {
        $this->authorizeAdmin();
        $this->assertConfiguredRole($roleKey);
        $actor = $this->user();
        if ($userId === $actor->id) {
            throw ApiProblem::conflict('Nao e permitido alterar papeis da propria conta.', 'self_role_assignment');
        }
        $user = User::find($userId) ?? throw ApiProblem::notFound();
        $manualRoles = $user->manual_roles ?? [];
        if (! in_array($roleKey, $manualRoles, true)) {
            if (in_array($roleKey, $user->roles ?? [], true)) {
                throw ApiProblem::conflict('Este papel vem do token ou de um mapeamento Entra e nao pode ser removido manualmente.', 'role_not_manual');
            }
            throw ApiProblem::notFound();
        }

        DB::transaction(function () use ($user, $roleKey, $manualRoles, $actor) {
            $trackedSources = $user->token_roles !== null || $user->directory_roles !== null || $user->token_groups !== null || $user->directory_groups !== null;
            $changes = ['manual_roles' => array_values(array_diff($manualRoles, [$roleKey]))];
            if (! $trackedSources) {
                $changes['roles'] = array_values(array_diff($user->roles ?? [], [$roleKey]));
            }
            $user->forceFill($changes);
            $user->recomputeEffectiveAccess();
            $user->save();
            AuditLogger::record('identity.manual_role_removed', 'user', $user->id, ['role' => $roleKey, 'source' => 'manual'], null, $actor, context: ['object_name' => $user->name.' — '.$roleKey]);
        });

        return response()->noContent();
    }

    private function assertConfiguredRole(string $roleKey): void
    {
        if (! in_array($roleKey, array_values((array) config('bpm.roles')), true)) {
            throw ApiProblem::notFound('Papel nao configurado.');
        }
    }
}
