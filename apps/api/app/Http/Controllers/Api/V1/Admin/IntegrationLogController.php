<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Authorization\Permissions;
use App\Http\Controllers\Api\V1\ApiController;
use App\Settings\IntegrationLog;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Log das integrações em Configurações (settings.manage): e-mail e notificações · Entra ID (login + importação).
 * Somente leitura; os dados já estão redigidos na origem (sem senhas, tokens, cursores ou corpo de e-mail).
 */
class IntegrationLogController extends ApiController
{
    public function index(Request $request, string $area, IntegrationLog $log): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        if (! in_array($area, IntegrationLog::AREAS, true)) {
            throw ApiProblem::notFound('Log desconhecido.');
        }
        $kinds = array_values(array_filter(explode(',', (string) $request->query('kind', ''))));
        $result = $log->page($area, [
            'kinds' => $kinds ?: IntegrationLog::KINDS[$area],
            'subsystem' => $request->query('subsystem') ? (string) $request->query('subsystem') : null,
            'failuresOnly' => $request->boolean('failures'),
            'q' => $request->query('q') ? (string) $request->query('q') : null,
            'since' => $request->query('since') ? (string) $request->query('since') : null,
        ], (int) $request->query('limit', 30), $request->query('cursor') ? (string) $request->query('cursor') : null);

        return response()->json([...$result, 'kinds' => IntegrationLog::KINDS[$area]])->header('Cache-Control', 'no-store');
    }
}
