<?php

namespace App\Http\Controllers\Api\V1;

use App\Forms\FormioSchema;
use App\Models\FormSubmission;
use App\Runtime\Access;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Resultados/Respostas": submissões por solicitação e etapa, filtradas por autorização. */
class ResultsController extends ApiController
{
    public function index(Request $request, string $key): JsonResponse
    {
        $catalog = $this->findCatalog($key);
        $user = $this->user();
        $visible = Access::visibleInstances($user)->where('catalog_id', $catalog->id)->select('id');

        $query = FormSubmission::query()
            ->whereIn('process_instance_id', $visible)
            ->with(['instance:id,number,requester_name,status', 'stage:id,stage_key,name', 'form:id,schema'])
            ->orderByDesc('created_at');
        if ($q = trim((string) $request->query('q', ''))) {
            $query->whereHas('instance', fn ($w) => $w->where('number', 'like', "%$q%"));
        }
        if ($stage = $request->query('stage')) {
            $query->whereHas('stage', fn ($w) => $w->where('stage_key', $stage));
        }
        $page = $query->paginate(min(100, max(5, (int) $request->query('perPage', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn (FormSubmission $s) => [
                'id' => $s->id,
                'instanceId' => $s->process_instance_id,
                'instanceNumber' => $s->instance?->number,
                'instanceStatus' => $s->instance?->status,
                'stageKey' => $s->stage?->stage_key,
                'stageName' => $s->stage?->name,
                'authorName' => $s->author_name,
                'revision' => $s->revision,
                'createdAt' => $s->created_at->toIso8601String(),
                'summary' => $this->summary($s),
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
            'stages' => $catalog->publishedVersion?->stages()->where('stage_type', 'user_task')->get(['stage_key', 'name']) ?? [],
        ]);
    }

    /** Submissão com o schema exato usado na época (renderização somente leitura). */
    public function show(string $id): JsonResponse
    {
        $s = FormSubmission::with('instance', 'stage', 'form')->find($id);
        if (! $s || ! Access::canViewInstance($this->user(), $s->instance)) {
            throw ApiProblem::notFound('Resposta não encontrada ou sem permissão.');
        }

        return response()->json([
            'id' => $s->id,
            'instance' => ['id' => $s->instance->id, 'number' => $s->instance->number],
            'stage' => ['key' => $s->stage->stage_key, 'name' => $s->stage->name],
            'form' => ['title' => $s->form->title, 'schema' => $s->form->schema, 'hash' => $s->form_schema_hash],
            'payload' => $s->payload,
            'metadata' => $s->metadata,
            'authorName' => $s->author_name,
            'revision' => $s->revision,
            'createdAt' => $s->created_at->toIso8601String(),
        ]);
    }

    /** @return list<array{label:string,value:string}> */
    private function summary(FormSubmission $s): array
    {
        $out = [];
        foreach (FormioSchema::dataComponents((array) ($s->form?->schema['components'] ?? [])) as $c) {
            if (count($out) >= 4) {
                break;
            }
            $value = $s->payload[$c['key']] ?? null;
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $label = FormioSchema::optionValues($c) && is_scalar($value) ? $this->optionLabel($c, (string) $value) : (is_bool($value) ? ($value ? 'Sim' : 'Não') : (string) $value);
            $out[] = ['label' => (string) ($c['label'] ?? $c['key']), 'value' => mb_substr($label, 0, 120)];
        }

        return $out;
    }

    private function optionLabel(array $c, string $value): string
    {
        $list = ($c['type'] ?? '') === 'select' ? ($c['data']['values'] ?? []) : ($c['values'] ?? []);
        foreach ($list as $o) {
            if ((string) ($o['value'] ?? '') === $value) {
                return (string) ($o['label'] ?? $value);
            }
        }

        return $value;
    }
}
