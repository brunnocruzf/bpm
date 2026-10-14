<?php

namespace App\Http\Middleware;

use App\Support\Correlation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        Correlation::set($request->headers->get('X-Correlation-Id'));
        Log::withContext(['correlation_id' => Correlation::id()]);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', Correlation::id());

        return $response;
    }
}
