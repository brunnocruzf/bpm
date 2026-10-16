<?php

namespace App\Http\Controllers\Api\V1;

use App\Authorization\Permissions;
use App\Identity\UserPhotoService;
use App\Models\User;
use App\Models\UserPhoto;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Foto de perfil importada do Entra ID. O próprio usuário vê só a sua; a administração (settings.manage)
 * vê a de qualquer usuário. Os bytes nunca saem sem autorização e nunca vão para auditoria ou logs.
 */
class UserPhotoController extends ApiController
{
    public function __construct(private readonly UserPhotoService $photos) {}

    public function me(Request $request): Response
    {
        return $this->serve($request, $this->user());
    }

    public function show(Request $request, string $id): Response
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        return $this->serve($request, User::find($id) ?? throw ApiProblem::notFound());
    }

    public function refresh(string $id): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $user = User::find($id) ?? throw ApiProblem::notFound();
        if ($user->auth_source !== User::SOURCE_ENTRA) {
            throw ApiProblem::validation(['photo' => ['Somente contas Entra ID têm foto importada.']]);
        }
        if (! $this->photos->enabled()) {
            throw ApiProblem::conflict('A importação de fotos está desligada ou o diretório não está configurado.', 'photos_disabled');
        }
        $queued = $this->photos->queueRefresh($user, force: true);
        AuditLogger::record('directory.photo_refresh_requested', 'user', $user->id, ['queued' => $queued], null, $this->user(), context: ['object_name' => $user->name]);

        return response()->json(['queued' => $queued], 202);
    }

    private function serve(Request $request, User $user): Response
    {
        $photo = UserPhoto::where('user_id', $user->id)->first();
        if (! $photo || $user->is_active === false || ! $this->photos->enabled() || ! ($file = $this->photos->contents($photo))) {
            throw ApiProblem::notFound('Foto não encontrada.');
        }
        $etag = '"'.$photo->version().'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'", 'Content-Disposition' => 'inline'];
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response($file['bytes'], 200, [...$headers, 'Content-Type' => $file['contentType']]);
    }
}
