<?php

namespace App\Http\Controllers\Api\V1;

use App\Backup\RuntimeState;
use Illuminate\Http\JsonResponse;

/** Progresso da restauração para a página de manutenção. Público e sem acesso ao banco; só etapa e percentual. */
class MaintenanceController extends ApiController
{
    public function show(RuntimeState $state): JsonResponse
    {
        $current = $state->read();
        $operation = (array) ($current['operation'] ?? []);

        return response()->json([
            'mode' => $current['mode'] ?? RuntimeState::NORMAL,
            'stage' => $operation['stage'] ?? null,
            'percent' => $operation['percent'] ?? null,
            'failed' => $current['failed'] ?? null,
        ])->header('Cache-Control', 'no-store');
    }
}
