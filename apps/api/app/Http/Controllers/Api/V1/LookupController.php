<?php

namespace App\Http\Controllers\Api\V1;

use App\Forms\FieldCatalog;
use App\Identity\EmployeeDirectory;
use App\Integrations\IntegrationUnavailable;
use App\Integrations\LookupAmbiguous;
use App\Integrations\LookupRunner;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Consultas sob demanda para os formulários (AX e endpoints publicados no catálogo; o servidor reconsulta ao salvar). */
class LookupController extends ApiController
{
    public function show(Request $request, string $source, LookupRunner $lookups): JsonResponse
    {
        $def = FieldCatalog::lookupSources()[$source] ?? throw ApiProblem::notFound('Fonte de consulta inexistente.');
        $value = trim((string) $request->query('value', ''));
        if ($value === '' || mb_strlen($value) > 60) {
            throw ApiProblem::validation(['value' => ['Informe o valor a consultar.']]);
        }
        try {
            $result = $lookups->run($source, $value);
        } catch (LookupAmbiguous $e) {
            throw ApiProblem::validation(['value' => [$e->getMessage()]]);
        } catch (IntegrationUnavailable $e) {
            if ($e->reason === 'not_configured') {
                throw ApiProblem::unavailable("A consulta \"{$def['label']}\" não está ativa no momento. Avise o administrador das integrações; seus dados não foram perdidos.");
            }
            throw ApiProblem::unavailable('O '.($def['system'] ?? 'AX').' não respondeu. Tente novamente; seus dados não foram perdidos.');
        }

        return response()->json([
            'found' => $result !== null,
            'data' => $result !== null ? EmployeeDirectory::withoutUserRefs($source, $result) : (object) [],
            'source' => $source,
            'transport' => $lookups->transportName($source),
            'at' => now('UTC')->toIso8601String(),
        ]);
    }
}
