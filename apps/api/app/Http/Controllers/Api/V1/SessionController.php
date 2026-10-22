<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\AuthMethods;
use App\Authorization\Permissions;
use App\Flow\StageCatalog;
use App\Forms\FieldCatalog;
use App\Identity\UserPhotoService;
use App\Models\BpmGroup;
use App\Models\TaskProjection;
use App\Models\User;
use App\Settings\SettingsRepository;
use App\Settings\Types\CategoriesSettings;
use Illuminate\Http\JsonResponse;

class SessionController extends ApiController
{
    public function me(UserPhotoService $photos): JsonResponse
    {
        $user = $this->user();
        $groups = BpmGroup::whereIn('key', $user->groups ?? [])->get(['key', 'name']);
        $myTasks = TaskProjection::whereIn('status', [TaskProjection::OPEN, TaskProjection::CLAIMED])
            ->where(fn ($q) => $q->where('assignee_id', $user->id)->orWhere(fn ($q2) => $q2->whereNull('assignee_id')->whereIn('candidate_group', $user->groups ?: ['__none__'])))
            ->count();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'area' => $user->area,
            'roles' => $user->roles ?? [],
            'groups' => $groups,
            'permissions' => [
                'design' => $user->hasRole('designer', 'admin'),
                'publish' => $user->hasRole('publisher', 'admin'),
                'start' => $user->hasRole('initiator'),
                'work' => $user->hasRole('task_worker'),
                'audit' => $user->hasRole('auditor', 'admin'),
                'admin' => $user->hasRole('admin'),
                // permissões granulares (Gates) — apenas apresentação; o servidor decide
                ...Permissions::for($user),
            ],
            'counters' => ['openTasks' => $myTasks],
            // foto importada do Entra ID: só a versão (os bytes vêm de /me/photo)
            'photo' => ($v = $photos->version($user)) && $photos->enabled() ? ['version' => $v] : null,
            // método do login atual (entra | demo | superadmin) e, para o SuperAdmin, a expiração da sessão
            'auth' => request()->attributes->get('bpm.auth', ['method' => AuthMethods::ENTRA]),
        ]);
    }

    /** Perfis fictícios do acesso de visitante (demonstração). Inexistente com a demonstração desabilitada e em produção. */
    public function devUsers(): JsonResponse
    {
        abort_unless(AuthMethods::demoEnabled(), 404);

        return response()->json(User::whereNotNull('username')->whereNull('entra_oid')->where('auth_source', User::SOURCE_DEMO)->where('is_active', true)
            ->orderBy('name')->get(['username', 'name', 'area', 'roles', 'groups']));
    }

    public function meta(SettingsRepository $settings): JsonResponse
    {
        $categories = new CategoriesSettings();

        return response()->json([
            'groups' => BpmGroup::orderBy('name')->get(['key', 'name', 'description']),
            'categories' => $categories->present($settings->value($categories))['items'],
            ...FieldCatalog::describe(),
            ...StageCatalog::describe(),
        ]);
    }
}
