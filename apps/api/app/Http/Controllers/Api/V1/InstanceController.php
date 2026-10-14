<?php

namespace App\Http\Controllers\Api\V1;

use App\Flow\FlowGraph;
use App\Forms\SubmissionContext;
use App\Forms\SubmissionProcessor;
use App\Models\ProcessInstance;
use App\Models\TaskProjection;
use App\Runtime\Access;
use App\Runtime\Idempotency;
use App\Runtime\InstanceService;
use App\Runtime\RuntimePresenter;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstanceController extends ApiController
{
    public function __construct(private readonly RuntimePresenter $presenter) {}

    /** Formulário de abertura ("Novo") da versão publicada. */
    public function startForm(string $key, SubmissionProcessor $processor): JsonResponse
    {
        $catalog = $this->findCatalog($key);
        $version = $catalog->publishedVersion ?? throw ApiProblem::conflict('Este fluxo ainda não possui versão publicada.', 'not_published');
        $user = $this->user();
        $opening = FlowGraph::load($version)->openingStage();
        $ctx = new SubmissionContext(user: $user, instance: null, instanceInfo: ['requesterName' => $user->name, 'requesterArea' => $user->area], preview: true);

        return response()->json([
            'flow' => ['key' => $catalog->key, 'name' => $catalog->name, 'description' => $catalog->description, 'category' => $catalog->category],
            'version' => $version->version,
            'deployed' => $version->isDeployed(),
            'canStart' => Access::canStart($user, $catalog),
            'stage' => $opening ? ['key' => $opening->stage_key, 'name' => $opening->name, 'description' => $opening->description] : null,
            'form' => $opening?->form ? ['id' => $opening->form->id, 'title' => $opening->form->title, 'schema' => $opening->form->schema] : null,
            'data' => $opening?->form ? $processor->prefill($opening->form->schema, $ctx) : (object) [],
        ]);
    }

    /** POST /process-instances — exige Idempotency-Key. */
    public function store(Request $request, InstanceService $service): JsonResponse
    {
        $data = $request->validate(['flowKey' => ['required', 'string', 'max:64'], 'data' => ['present', 'array']]);
        $user = $this->user();

        return Idempotency::run($request, $user, 'instance.start', function () use ($service, $user, $data) {
            $instance = $service->start($user, $data['flowKey'], $data['data']);

            return [202, ['instance' => $this->presenter->instanceSummary($instance->load('catalog', 'definitionVersion')), 'message' => 'Solicitação recebida. Processando no engine.']];
        }, required: true);
    }

    /** GET /process-instances — grid filtrado pela autorização do usuário. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $query = Access::visibleInstances($user)->with(['catalog', 'definitionVersion', 'tasks' => fn ($q) => $q->whereIn('status', TaskProjection::ACTIVE)->with('assignee')]);

        if ($flow = $request->query('flow')) {
            $query->whereHas('catalog', fn ($q) => $q->where('key', $flow));
        }
        if ($status = $request->query('status')) {
            $query->whereIn('status', explode(',', (string) $status));
        }
        if ($request->query('scope') === 'mine') {
            $query->where('requester_id', $user->id);
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(fn ($w) => $w->where('number', 'like', "%$q%")->orWhere('requester_name', 'like', "%$q%")->orWhere('requester_area', 'like', "%$q%"));
        }
        $perPage = min(100, max(5, (int) $request->query('perPage', 20)));
        $page = $query->orderByDesc('started_at')->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn ($i) => $this->presenter->instanceSummary($i))->all(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
        ]);
    }

    public function metrics(Request $request): JsonResponse
    {
        $user = $this->user();
        $base = Access::visibleInstances($user);
        if ($flow = $request->query('flow')) {
            $base->whereHas('catalog', fn ($q) => $q->where('key', $flow));
        }
        $ids = (clone $base)->pluck('id');
        $completedTasks = TaskProjection::whereIn('process_instance_id', $ids)->where('status', TaskProjection::COMPLETED)->whereNotNull('due_at')->where('completed_at', '>=', now()->subDays(30))->get(['due_at', 'completed_at']);
        $onTime = $completedTasks->count() ? round(100 * $completedTasks->filter(fn ($t) => $t->completed_at <= $t->due_at)->count() / $completedTasks->count()) : null;

        return response()->json([
            'running' => (clone $base)->whereIn('status', [ProcessInstance::STARTING, ProcessInstance::RUNNING])->count(),
            'awaitingMe' => TaskProjection::whereIn('process_instance_id', $ids)->whereIn('status', [TaskProjection::OPEN, TaskProjection::CLAIMED])
                ->where(fn ($q) => $q->where('assignee_id', $user->id)->orWhere(fn ($q2) => $q2->whereNull('assignee_id')->whereIn('candidate_group', $user->groups ?: ['__none__'])))->count(),
            'completedThisMonth' => (clone $base)->where('status', ProcessInstance::COMPLETED)->where('ended_at', '>=', now()->startOfMonth())->count(),
            'onTimePercent' => $onTime,
            'overdueTasks' => TaskProjection::whereIn('process_instance_id', $ids)->whereIn('status', [TaskProjection::OPEN, TaskProjection::CLAIMED])->where('due_at', '<', now())->count(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $instance = $this->visible($id);

        return response()->json($this->presenter->instanceDetail($instance, $this->user()));
    }

    public function timeline(string $id): JsonResponse
    {
        return response()->json(['data' => $this->presenter->timeline($this->visible($id))]);
    }

    private function visible(string $id): ProcessInstance
    {
        $instance = ProcessInstance::with('catalog', 'definitionVersion')->find($id);
        if (! $instance || ! Access::canViewInstance($this->user(), $instance)) {
            throw ApiProblem::notFound('Solicitação não encontrada ou sem permissão de acesso.');
        }

        return $instance;
    }
}
