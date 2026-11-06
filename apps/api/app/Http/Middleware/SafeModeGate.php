<?php

namespace App\Http\Middleware;

use App\Authorization\Permissions;
use App\Backup\RuntimeState;
use App\Models\User;
use App\Support\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Modo seguro: até a liberação só administradores entram. Trabalho feito agora se perderia se a restauração fosse desfeita. */
class SafeModeGate
{
    public function __construct(private readonly RuntimeState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->state->mode() === RuntimeState::SAFE) {
            $user = $request->user();
            if (! $user instanceof User || ! Permissions::allows($user, Permissions::SETTINGS_MANAGE)) {
                throw new ApiProblem(503, 'Sistema em revisão', 'Os dados foram restaurados e um administrador está conferindo. O sistema volta em breve.', 'safe_mode');
            }
        }

        return $next($request);
    }
}
