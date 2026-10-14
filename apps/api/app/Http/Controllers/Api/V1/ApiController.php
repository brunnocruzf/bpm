<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProcessCatalog;
use App\Models\ProcessDefinitionVersion;
use App\Models\User;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\Auth;

abstract class ApiController extends Controller
{
    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function requireRole(string ...$roles): void
    {
        if (! $this->user()->hasRole(...$roles)) {
            throw ApiProblem::forbidden();
        }
    }

    protected function findCatalog(string $key): ProcessCatalog
    {
        return ProcessCatalog::where('key', $key)->first() ?? throw ApiProblem::notFound('Fluxo não encontrado.');
    }

    protected function findVersion(ProcessCatalog $catalog, int|string $version): ProcessDefinitionVersion
    {
        return ProcessDefinitionVersion::where('catalog_id', $catalog->id)->where('version', (int) $version)->first()
            ?? throw ApiProblem::notFound('Versão não encontrada.');
    }

    protected function canReadDefinitions(): bool
    {
        return $this->user()->hasRole('designer', 'publisher', 'admin', 'auditor');
    }
}
