<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SupplierAction;
use App\Models\TaskProjection;
use App\Runtime\Access;
use App\Runtime\Idempotency;
use App\Runtime\RuntimePresenter;
use App\Runtime\SupplierActionService;
use App\Runtime\TaskService;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends ApiController
{
    public function __construct(
        private readonly RuntimePresenter $presenter,
        private readonly TaskService $tasks,
    ) {}

    /** GET /tasks?scope=mine|groups|started|completed */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $scope = (string) $request->query('scope', 'mine');
        $groups = $user->groups ?: ['__none__'];
        $query = TaskProjection::query()->with(['instance.catalog', 'assignee', 'completedByUser']);

        switch ($scope) {
            case 'mine':
                $query->whereIn('status', [TaskProjection::CLAIMED, TaskProjection::OPEN, TaskProjection::COMPLETING])
                    ->where(fn ($q) => $q->where('assignee_id', $user->id)->orWhere(fn ($q2) => $q2->whereNull('assignee_id')->whereIn('candidate_group', $groups)));
                break;
            case 'groups':
                $query->whereIn('status', [TaskProjection::OPEN, TaskProjection::CLAIMED, TaskProjection::COMPLETING])->whereIn('candidate_group', $groups);
                break;
            case 'started':
                $query->whereIn('status', TaskProjection::ACTIVE)->whereHas('instance', fn ($q) => $q->where('requester_id', $user->id));
                break;
            case 'completed':
                $query->where('status', TaskProjection::COMPLETED)->where('completed_by', $user->id);
                break;
            default:
                throw ApiProblem::validation(['scope' => ['Escopo inválido.']]);
        }
        if ($flow = $request->query('flow')) {
            $query->whereHas('instance.catalog', fn ($q) => $q->where('key', $flow));
        }
        $order = $scope === 'completed' ? ['completed_at', 'desc'] : ['created_at', 'asc'];
        $page = $query->orderBy(...$order)->paginate(min(100, max(5, (int) $request->query('perPage', 25))));

        return response()->json([
            'data' => collect($page->items())->map(fn ($t) => $this->presenter->taskSummary($t, $user))->all(),
            'meta' => ['page' => $page->currentPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->presenter->taskDetail($this->visible($id), $this->user()));
    }

    public function claim(string $id): JsonResponse
    {
        $task = $this->tasks->claim($this->user(), $this->visible($id));

        return response()->json($this->presenter->taskSummary($task->fresh(['instance.catalog', 'assignee']), $this->user()));
    }

    public function release(string $id): JsonResponse
    {
        $task = $this->tasks->release($this->user(), $this->visible($id));

        return response()->json($this->presenter->taskSummary($task->fresh(['instance.catalog', 'assignee']), $this->user()));
    }

    public function reassign(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['userId' => ['required', 'uuid'], 'justification' => ['required', 'string', 'max:1000']]);
        $task = $this->tasks->reassign($this->user(), $this->visible($id), $data['userId'], $data['justification']);

        return response()->json($this->presenter->taskSummary($task->fresh(['instance.catalog', 'assignee']), $this->user()));
    }

    public function saveDraft(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['data' => ['present', 'array']]);
        $this->tasks->saveDraft($this->user(), $this->visible($id), $data['data']);

        return response()->json(['saved' => true, 'at' => now()->toIso8601String()]);
    }

    /** POST /tasks/{id}/complete — If-Match/rowVersion + Idempotency-Key opcional. */
    public function complete(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['data' => ['present', 'array'], 'rowVersion' => ['nullable', 'integer']]);
        $task = $this->visible($id);
        $rowVersion = $data['rowVersion'] ?? ($request->header('If-Match') ? (int) trim((string) $request->header('If-Match'), '"W/') : null);
        $user = $this->user();

        return Idempotency::run($request, $user, 'task.complete:'.$task->id, function () use ($user, $task, $data, $rowVersion) {
            $updated = $this->tasks->complete($user, $task, $data['data'], $rowVersion);

            return [202, ['task' => $this->presenter->taskSummary($updated->fresh(['instance.catalog', 'assignee']), $user), 'message' => 'Etapa enviada. Processando no engine.']];
        });
    }

    public function completeAction(Request $request, string $id, string $actionId, SupplierActionService $service): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:4000'],
            'attachmentIds' => ['nullable', 'array', 'max:20'],
            'attachmentIds.*' => ['uuid'],
        ]);
        $task = $this->visible($id);
        $action = SupplierAction::find($actionId) ?? throw ApiProblem::notFound('Ação não encontrada.');
        $service->complete($this->user(), $task, $action, $data['notes'] ?? null, $data['attachmentIds'] ?? []);

        return response()->json(['actions' => $service->present($task->instance)]);
    }

    private function visible(string $id): TaskProjection
    {
        $task = TaskProjection::with('instance.catalog', 'stage')->find($id);
        if (! $task || ! Access::canViewInstance($this->user(), $task->instance)) {
            throw ApiProblem::notFound('Tarefa não encontrada ou sem permissão de acesso.');
        }

        return $task;
    }
}
