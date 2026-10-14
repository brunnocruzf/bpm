<?php

namespace App\Http\Controllers\Api\V1;

use App\Authorization\Permissions;
use App\Models\ProcessInstance;
use App\Models\ReportJob;
use App\Models\ReportTemplate;
use App\Reports\OperationalReports;
use App\Reports\ReportService;
use App\Reports\ReportTemplateService;
use App\Runtime\Access;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Relatórios: catálogo, solicitação (assíncrona), acompanhamento e download protegido. */
class ReportController extends ApiController
{
    public function __construct(private readonly ReportService $reports) {}

    public function catalog(): JsonResponse
    {
        $user = $this->user();

        return response()->json([
            'operational' => Permissions::allows($user, Permissions::REPORTS_OPERATIONAL)
                ? collect(OperationalReports::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'formats' => ['pdf', 'csv', 'xlsx']])->values()
                : [],
            'filters' => OperationalReports::FILTERS,
            'canInstance' => Permissions::allows($user, Permissions::REPORTS_INSTANCE),
            'canManageTemplates' => Permissions::allows($user, Permissions::REPORTS_TEMPLATES),
            'templates' => ReportTemplate::where('status', 'active')->get(['id', 'key', 'name', 'kind', 'engine', 'version']),
        ]);
    }

    public function storeForInstance(Request $request, string $id): JsonResponse
    {
        $instance = ProcessInstance::find($id);
        if (! $instance || ! Access::canViewInstance($this->user(), $instance)) {
            throw ApiProblem::notFound();
        }
        $job = $this->reports->requestInstanceReport($instance, $this->user(), (array) $request->json()->all(), $request->header('Idempotency-Key'));

        return response()->json($this->present($job), 202);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'string', 'max:40'], 'format' => ['required', 'in:pdf,csv,xlsx'], 'filters' => ['sometimes', 'array']]);
        $job = $this->reports->requestOperational((string) $data['type'], $this->user(), $data, (string) $data['format'], $request->header('Idempotency-Key'));

        return response()->json($this->present($job), 202);
    }

    public function index(Request $request): JsonResponse
    {
        $q = ReportJob::where('requested_by', $this->user()->id)->orderByDesc('created_at');
        if ($instance = $request->query('processInstanceId')) {
            $q->where('process_instance_id', (string) $instance);
        }

        return response()->json(['data' => $q->limit(50)->get()->map(fn ($j) => $this->present($j))]);
    }

    public function show(string $id): JsonResponse
    {
        $job = ReportJob::find($id) ?? throw ApiProblem::notFound();
        if (! Gate::allows('view', $job)) {
            throw ApiProblem::notFound();
        }

        return response()->json($this->present($job));
    }

    public function download(Request $request, string $id): StreamedResponse
    {
        $job = ReportJob::find($id) ?? throw ApiProblem::notFound();
        if (! Gate::allows('view', $job)) {
            throw ApiProblem::notFound();
        }
        if ($job->status !== ReportJob::READY) {
            throw ApiProblem::conflict($job->status === ReportJob::EXPIRED ? 'O arquivo expirou. Gere o relatório novamente.' : 'O relatório ainda não está pronto.', 'report_not_ready');
        }
        $purpose = (string) $request->query('purpose', 'download');

        return $this->reports->download($job, $this->user(), in_array($purpose, ['download', 'preview', 'print'], true) ? $purpose : 'download');
    }

    // ------------------------------------------------------------------ modelos (reports.templates)

    public function templates(): JsonResponse
    {
        Gate::authorize(Permissions::REPORTS_TEMPLATES);

        return response()->json(['data' => ReportTemplate::orderBy('key')->orderByDesc('version')->get()->map(fn (ReportTemplate $t) => [
            'id' => $t->id, 'key' => $t->key, 'version' => $t->version, 'name' => $t->name, 'kind' => $t->kind, 'engine' => $t->engine, 'status' => $t->status,
            'options' => $t->options, 'fileSha256' => $t->file_sha256 ? substr($t->file_sha256, 0, 16) : null, 'fileSize' => $t->file_size,
            'createdBy' => $t->created_by_name, 'createdAt' => $t->created_at?->toIso8601String(), 'activatedAt' => $t->activated_at?->toIso8601String(),
        ])]);
    }

    public function storeTemplate(Request $request, ReportTemplateService $service): JsonResponse
    {
        Gate::authorize(Permissions::REPORTS_TEMPLATES);
        $t = $service->createVersion($request->all(), $request->file('file'), $this->user());

        return response()->json(['id' => $t->id, 'key' => $t->key, 'version' => $t->version, 'status' => $t->status], 201);
    }

    public function activateTemplate(string $id, ReportTemplateService $service): JsonResponse
    {
        Gate::authorize(Permissions::REPORTS_TEMPLATES);
        $t = ReportTemplate::find($id) ?? throw ApiProblem::notFound();
        $service->activate($t, $this->user());

        return response()->json(['id' => $t->id, 'status' => 'active']);
    }

    /** @return array<string,mixed> */
    private function present(ReportJob $j): array
    {
        return [
            'id' => $j->id, 'type' => $j->report_type, 'format' => $j->format, 'status' => $j->status, 'progress' => $j->progress,
            'parameters' => $j->parameters, 'processInstanceId' => $j->process_instance_id, 'renderer' => $j->renderer,
            'template' => $j->template_id ? ['id' => $j->template_id, 'version' => $j->template_version] : null,
            'errorCode' => $j->error_code, 'error' => $j->error_message, 'fileName' => $j->artifact_name, 'size' => $j->artifact_size, 'rows' => $j->row_count,
            'createdAt' => $j->created_at?->toIso8601String(), 'finishedAt' => $j->finished_at?->toIso8601String(), 'expiresAt' => $j->expires_at?->toIso8601String(),
        ];
    }
}
