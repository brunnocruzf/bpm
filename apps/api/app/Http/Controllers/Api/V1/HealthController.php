<?php

namespace App\Http\Controllers\Api\V1;

use App\Engine\FlowableRestClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends ApiController
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'UP']);
    }

    public function ready(FlowableRestClient $engine): JsonResponse
    {
        try {
            DB::select('select 1');
            $db = true;
        } catch (Throwable) {
            $db = false;
        }
        $engineUp = $engine->health();
        $ok = $db && $engineUp;

        return response()->json(['status' => $ok ? 'UP' : 'DEGRADED', 'database' => $db, 'engine' => $engineUp], $ok ? 200 : 503);
    }
}
