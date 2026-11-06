<?php

namespace App\Http\Middleware;

use App\Backup\RuntimeState;
use App\Support\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Durante a troca dos bancos a API responde sem tocar no banco: este middleware roda antes de qualquer acesso a ele. */
class HoldDuringRestore
{
    public function __construct(private readonly RuntimeState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->state->mode() === RuntimeState::MAINTENANCE && ! $request->is('api/v1/maintenance', 'api/v1/health')) {
            throw new ApiProblem(503, 'Sistema em manutenção', 'Um administrador está restaurando os dados. O sistema volta sozinho.', 'maintenance');
        }

        return $next($request);
    }
}
