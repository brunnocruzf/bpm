<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditDiff;
use App\Flow\FlowDeletionService;
use App\Flow\FlowFactory;
use App\Flow\FlowPresenter;
use App\Models\ProcessCatalog;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessInstance;
use App\Runtime\Access;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FlowController extends ApiController
{
    /** GET /catalog/processes — catálogo permitido ao usuário. */
    public function catalog(Request $request): JsonResponse
    {
        $user = $this->user();
        $designer = $this->canReadDefinitions();

        $query = ProcessCatalog::query()->with('publishedVersion')->orderBy('category')->orderBy('name');
        if (! $designer) {
            $query->whereNotNull('current_published_version_id')->where('status', 'active');
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('description', 'like', "%$q%"));
        }

        $totals = ProcessInstance::selectRaw('catalog_id, count(*) as total')->groupBy('catalog_id')->pluck('total', 'catalog_id');
        $open = ProcessInstance::whereIn('status', [ProcessInstance::STARTING, ProcessInstance::RUNNING])->selectRaw('catalog_id, count(*) as total')->groupBy('catalog_id')->pluck('total', 'catalog_id');
        $items = $query->get()->map(fn (ProcessCatalog $c) => [
            ...FlowPresenter::catalog($c, (int) ($totals[$c->id] ?? 0), (int) ($open[$c->id] ?? 0)),
            'canStart' => $c->publishedVersion?->isDeployed() && Access::canStart($user, $c),
        ]);

        return response()->json(['data' => $items]);
    }

    /** POST /flows — fluxo + versão draft + Etapa 1 + formulário vazio + Start→Etapa 1→Fim. */
    public function store(Request $request, FlowFactory $factory): JsonResponse
    {
        $this->requireRole('designer', 'admin');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:90'],
            'key' => ['nullable', 'string', 'max:64'],
            'category' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:240'],
            'instancePrefix' => ['nullable', 'string', 'regex:/^[A-Za-z]{2,6}$/'],
        ]);
        $catalog = $factory->create($data, $this->user());
        $version = $catalog->latestVersion();

        return response()->json([
            'flow' => FlowPresenter::catalog($catalog),
            'version' => FlowPresenter::version($version),
        ], 201);
    }

    public function show(string $key): JsonResponse
    {
        $catalog = $this->findCatalog($key);
        if (! $this->canReadDefinitions() && ! $catalog->current_published_version_id) {
            throw ApiProblem::notFound('Fluxo não encontrado.');
        }

        return response()->json([
            'flow' => FlowPresenter::catalog($catalog),
            'versions' => $catalog->versions()->get()->map(fn (ProcessDefinitionVersion $v) => [
                'version' => $v->version,
                'status' => $v->status,
                'hash' => $v->hash,
                'publishedAt' => $v->published_at?->toIso8601String(),
                'engineDeployed' => $v->engine_definition_id !== null,
                'createdAt' => $v->created_at?->toIso8601String(),
                'updatedAt' => $v->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    /** Exclui um fluxo que nunca foi publicado (somente rascunho, sem solicitações). Confirmação: ?confirm=<identificador>. */
    public function destroy(Request $request, string $key, FlowDeletionService $service): Response
    {
        $this->requireRole('designer', 'admin');
        $service->deleteFlow($this->findCatalog($key), (string) $request->query('confirm', ''), $this->user());

        return response()->noContent();
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $this->requireRole('designer', 'admin');
        $catalog = $this->findCatalog($key);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:3', 'max:90'],
            'category' => ['sometimes', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:240'],
            'instancePrefix' => ['sometimes', 'string', 'regex:/^[A-Za-z]{2,6}$/'],
            'initiatorGroups' => ['sometimes', 'nullable', 'array'],
            'initiatorGroups.*' => ['string', 'exists:bpm_groups,key'],
        ]);
        $before = $catalog->only(['name', 'category', 'description', 'instance_prefix', 'initiator_groups']);
        $catalog->fill(array_filter([
            'name' => $data['name'] ?? null,
            'category' => $data['category'] ?? null,
            'instance_prefix' => isset($data['instancePrefix']) ? strtoupper($data['instancePrefix']) : null,
        ], fn ($v) => $v !== null));
        if (array_key_exists('description', $data)) {
            $catalog->description = $data['description'];
        }
        if (array_key_exists('initiatorGroups', $data)) {
            $catalog->initiator_groups = $data['initiatorGroups'] ?: null;
        }
        $catalog->save();
        AuditLogger::record('flow.updated', 'process_catalog', $catalog->id, ['fields' => array_keys($data)], diff: AuditDiff::between($before, $catalog->only(array_keys($before))), context: ['object_name' => $catalog->name, 'flow_key' => $catalog->key]);

        return response()->json(['flow' => FlowPresenter::catalog($catalog)]);
    }
}
