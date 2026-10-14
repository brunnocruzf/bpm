<?php

namespace App\Http\Controllers\Api\V1;

use App\Flow\BpmnGenerator;
use App\Flow\BpmnImporter;
use App\Flow\FlowDeletionService;
use App\Flow\FlowGraph;
use App\Flow\FlowPresenter;
use App\Flow\FormSchemaService;
use App\Flow\PublishService;
use App\Flow\StageService;
use App\Flow\VersionService;
use App\Forms\FormioSchema;
use App\Models\FormSchemaVersion;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessStageVersion;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Versões, etapas, rotas, formulários, BPMN, validação e publicação. */
class DefinitionController extends ApiController
{
    private function readable(string $key, int|string $v): ProcessDefinitionVersion
    {
        if (! $this->canReadDefinitions()) {
            throw ApiProblem::forbidden();
        }

        return $this->findVersion($this->findCatalog($key), $v);
    }

    private function writable(string $key, int|string $v): ProcessDefinitionVersion
    {
        $this->requireRole('designer', 'admin');

        return $this->findVersion($this->findCatalog($key), $v);
    }

    private function stage(ProcessDefinitionVersion $version, string $stageKey): ProcessStageVersion
    {
        return ProcessStageVersion::where('definition_version_id', $version->id)->where('stage_key', $stageKey)->first()
            ?? throw ApiProblem::notFound('Etapa não encontrada.');
    }

    private function form(ProcessDefinitionVersion $version, string $formKey): FormSchemaVersion
    {
        return FormSchemaVersion::where('definition_version_id', $version->id)->where('form_key', $formKey)->first()
            ?? throw ApiProblem::notFound('Formulário não encontrado.');
    }

    public function versions(string $key): JsonResponse
    {
        if (! $this->canReadDefinitions()) {
            throw ApiProblem::forbidden();
        }
        $catalog = $this->findCatalog($key);

        return response()->json(['data' => $catalog->versions()->get()->map(fn ($v) => [
            'version' => $v->version, 'status' => $v->status, 'hash' => $v->hash,
            'publishedAt' => $v->published_at?->toIso8601String(), 'engineDeployed' => $v->engine_definition_id !== null,
        ])]);
    }

    /** POST /definitions/{key}/versions — novo rascunho clonado da última versão. */
    public function createDraft(string $key, VersionService $service): JsonResponse
    {
        $this->requireRole('designer', 'admin');
        $version = $service->createDraft($this->findCatalog($key), $this->user());

        return response()->json(FlowPresenter::version($version), 201);
    }

    /** Descarta o rascunho em edição de um fluxo já publicado (a versão publicada continua valendo). */
    public function discardDraft(string $key, string $v, FlowDeletionService $service): Response
    {
        $version = $this->writable($key, $v);
        $service->discardDraft($version->catalog, $version, $this->user());

        return response()->noContent();
    }

    public function show(string $key, string $v): JsonResponse
    {
        return response()->json(FlowPresenter::version($this->readable($key, $v)));
    }

    public function addStage(Request $request, string $key, string $v, StageService $service): JsonResponse
    {
        $version = $this->writable($key, $v);
        $data = $request->validate([
            'type' => ['required', 'string'],
            'after' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);
        $stage = $service->add($version, $data['type'], $data['after'] ?? null, $data['name'] ?? null, $this->user());

        return response()->json(['stage' => ['key' => $stage->stage_key], 'version' => FlowPresenter::version($version->fresh())], 201);
    }

    public function updateStage(Request $request, string $key, string $v, string $stageKey, StageService $service): JsonResponse
    {
        $version = $this->writable($key, $v);
        $attrs = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', 'string'],
            'lane' => ['sometimes', 'nullable', 'string', 'max:40'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'config' => ['sometimes', 'array'],
            'lockVersion' => ['sometimes', 'integer'],
        ]);
        $service->update($this->stage($version, $stageKey), $attrs, $this->user());

        return response()->json(FlowPresenter::version($version->fresh()));
    }

    public function deleteStage(string $key, string $v, string $stageKey, StageService $service): JsonResponse
    {
        $version = $this->writable($key, $v);
        $service->delete($this->stage($version, $stageKey), $this->user());

        return response()->json(FlowPresenter::version($version->fresh()));
    }

    public function reorderStages(Request $request, string $key, string $v, StageService $service): JsonResponse
    {
        $version = $this->writable($key, $v);
        $data = $request->validate(['keys' => ['required', 'array', 'max:500'], 'keys.*' => ['string', 'max:64']]);
        $service->reorder($version, $data['keys'], $this->user());

        return response()->json(FlowPresenter::version($version->fresh()));
    }

    public function setTransitions(Request $request, string $key, string $v, string $stageKey, StageService $service): JsonResponse
    {
        $version = $this->writable($key, $v);
        $data = $request->validate([
            'transitions' => ['present', 'array', 'max:20'],
            'transitions.*.target' => ['required', 'string', 'max:64'],
            'transitions.*.condition' => ['nullable', 'array'],
            'transitions.*.isDefault' => ['nullable', 'boolean'],
            'transitions.*.label' => ['nullable', 'string', 'max:120'],
        ]);
        $service->setTransitions($this->stage($version, $stageKey), $data['transitions'], $this->user());

        return response()->json(FlowPresenter::version($version->fresh()));
    }

    /** GET .../forms/{formKey} — somente schema; sem credenciais nem URLs administrativas. */
    public function showForm(string $key, string $v, string $formKey): JsonResponse
    {
        $version = $this->readable($key, $v);
        $form = $this->form($version, $formKey);

        return response()->json([
            'id' => $form->id, 'formKey' => $form->form_key, 'title' => $form->title, 'status' => $form->status,
            'hash' => $form->hash, 'stageKey' => $form->stage?->stage_key, 'schema' => $form->schema,
            'lockVersion' => $version->lock_version,
        ]);
    }

    /** PUT .../forms/{formKey} — salva schema Form.io apenas em rascunho, após sanitização. */
    public function saveForm(Request $request, string $key, string $v, string $formKey, FormSchemaService $service): JsonResponse
    {
        $version = $this->writable($key, $v);
        $data = $request->validate([
            'schema' => ['required', 'array'],
            'schema.components' => ['present', 'array'],
            'title' => ['nullable', 'string', 'max:160'],
            'lockVersion' => ['nullable', 'integer'],
        ]);
        $form = $service->save($this->form($version, $formKey), $data['schema'], $data['title'] ?? null, $this->user(), $data['lockVersion'] ?? null);

        return response()->json([
            'id' => $form->id, 'formKey' => $form->form_key, 'title' => $form->title, 'hash' => $form->hash,
            'schema' => $form->schema, 'lockVersion' => $version->fresh()->lock_version,
            'fieldCount' => count(FormioSchema::dataComponents($form->schema['components'] ?? [])),
        ]);
    }

    public function copyForm(Request $request, string $key, string $v, string $formKey, FormSchemaService $service): JsonResponse
    {
        $version = $this->writable($key, $v);
        $data = $request->validate(['sourceFormId' => ['required', 'uuid']]);
        $source = FormSchemaVersion::find($data['sourceFormId']) ?? throw ApiProblem::notFound('Formulário modelo não encontrado.');
        $form = $service->copyFrom($this->form($version, $formKey), $source, $this->user());

        return response()->json(['id' => $form->id, 'formKey' => $form->form_key, 'schema' => $form->schema]);
    }

    /** Biblioteca para "usar como modelo" (cópia independente). */
    public function formLibrary(Request $request): JsonResponse
    {
        $this->requireRole('designer', 'admin');
        $q = trim((string) $request->query('q', ''));
        $forms = DB::table('form_schema_version')
            ->join('process_definition_version as v', 'v.id', '=', 'form_schema_version.definition_version_id')
            ->join('process_catalog as c', 'c.id', '=', 'v.catalog_id')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('form_schema_version.title', 'like', "%$q%")->orWhere('c.name', 'like', "%$q%")))
            ->orderByDesc('form_schema_version.updated_at')
            ->limit(50)
            ->get(['form_schema_version.id', 'form_schema_version.title', 'form_schema_version.form_key', 'form_schema_version.schema', 'c.name as flow_name', 'v.version']);

        return response()->json(['data' => $forms->map(fn (object $f) => [
            'id' => $f->id, 'title' => $f->title, 'formKey' => $f->form_key, 'flowName' => $f->flow_name, 'version' => (int) $f->version,
            'fieldCount' => count(FormioSchema::dataComponents((array) (json_decode((string) $f->schema, true)['components'] ?? []))),
        ])]);
    }

    /** BPMN XML: rascunho gerado na hora; publicada devolve o artefato congelado. */
    public function bpmn(string $key, string $v, BpmnGenerator $generator): Response
    {
        $version = $this->readable($key, $v);
        $xml = $version->isDraft() || ! $version->bpmn_xml ? $generator->generate(FlowGraph::load($version)) : $version->bpmn_xml;

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$version->catalog->key.'-v'.$version->version.'.bpmn"',
        ]);
    }

    public function importBpmn(Request $request, string $key, string $v, BpmnImporter $importer): JsonResponse
    {
        $version = $this->writable($key, $v);
        $data = $request->validate(['xml' => ['required', 'string', 'max:2097152']]);
        $summary = $importer->import($version, $data['xml'], $this->user());

        return response()->json(['summary' => $summary, 'version' => FlowPresenter::version($version->fresh())]);
    }

    public function validateVersion(string $key, string $v, PublishService $service): JsonResponse
    {
        $version = $this->readable($key, $v);
        $problems = $service->validate($version);

        return response()->json(['problems' => $problems, 'valid' => ! collect($problems)->contains('severity', 'error')]);
    }

    public function publish(string $key, string $v, PublishService $service): JsonResponse
    {
        $this->requireRole('publisher', 'admin');
        $version = $this->findVersion($this->findCatalog($key), $v);
        $published = $service->publish($version, $this->user());

        return response()->json(FlowPresenter::version($published), 202);
    }
}
