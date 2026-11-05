<?php

namespace App\Runtime;

use App\Authorization\Permissions;
use App\Flow\StageCatalog;
use App\Forms\SubmissionContext;
use App\Forms\SubmissionProcessor;
use App\Models\AuditEvent;
use App\Models\BpmGroup;
use App\Models\EngineCommand;
use App\Models\FormSubmission;
use App\Models\ProcessInstance;
use App\Models\TaskDraft;
use App\Models\TaskProjection;
use App\Models\User;

class RuntimePresenter
{
    /** @var array<string,string>|null */
    private ?array $groupNames = null;

    public function __construct(
        private readonly SubmissionProcessor $processor,
        private readonly SupplierActionService $actions,
    ) {}

    /** @return array<string,mixed> */
    public function instanceSummary(ProcessInstance $i): array
    {
        $active = $i->relationLoaded('tasks') ? $i->tasks->filter(fn ($t) => $t->isActive()) : $i->tasks()->whereIn('status', TaskProjection::ACTIVE)->with('assignee')->get();

        return [
            'id' => $i->id,
            'number' => $i->number,
            'flow' => ['key' => $i->catalog->key, 'name' => $i->catalog->name, 'category' => $i->catalog->category],
            'version' => $i->definitionVersion?->version,
            'requesterName' => $i->requester_name,
            'requesterArea' => $i->requester_area,
            'startedAt' => $i->started_at->toIso8601String(),
            'endedAt' => $i->ended_at?->toIso8601String(),
            'status' => $i->status,
            'result' => $i->result,
            'resultLabel' => $i->result ? (StageCatalog::END_RESULTS[$i->result] ?? $i->result) : null,
            'processing' => $i->status === ProcessInstance::STARTING || $active->contains(fn ($t) => $t->status === TaskProjection::COMPLETING),
            'currentTasks' => $active->filter(fn ($t) => $t->status !== TaskProjection::COMPLETING)->values()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'candidateGroup' => $t->candidate_group,
                'candidateGroupName' => $this->groupName($t->candidate_group),
                'assigneeName' => $t->assignee?->name,
                'dueAt' => $t->due_at?->toIso8601String(),
                'overdue' => $t->due_at !== null && $t->due_at->isPast(),
            ])->all(),
            'ageDays' => (int) $i->started_at->diffInDays($i->ended_at ?? now()),
        ];
    }

    /** @return array<string,mixed> */
    public function instanceDetail(ProcessInstance $i, User $user): array
    {
        $i->load('catalog', 'definitionVersion', 'tasks.assignee', 'tasks.completedByUser');
        $submissions = FormSubmission::where('process_instance_id', $i->id)->with('stage')->orderBy('created_at')->get();
        $completedElements = $i->tasks->where('status', TaskProjection::COMPLETED)->map(fn ($t) => $t->stage_key)->unique()->values()->all();
        $activeElements = $i->tasks->filter(fn ($t) => $t->isActive())->map(fn ($t) => $t->stage_key)->unique()->values()->all();

        return [
            ...$this->instanceSummary($i),
            'tasks' => $i->tasks->map(fn ($t) => $this->taskSummary($t, $user, false))->all(),
            'submissions' => $submissions->map(fn ($s) => [
                'id' => $s->id,
                'stageKey' => $s->stage?->stage_key,
                'stageName' => $s->stage?->name,
                'authorName' => $s->author_name,
                'revision' => $s->revision,
                'createdAt' => $s->created_at->toIso8601String(),
            ])->all(),
            'attachments' => $i->attachments()->orderBy('created_at')->get()->map(fn ($a) => AttachmentService::present($a))->all(),
            'supplierActions' => $this->actions->present($i, true),
            'diagram' => [
                'bpmnXml' => $i->definitionVersion->bpmn_xml,
                'activeElements' => $activeElements,
                'completedElements' => array_values(array_diff($completedElements, $activeElements)),
                'endElement' => $i->end_stage_key,
            ],
            'pendingCommands' => EngineCommand::where('process_instance_id', $i->id)->whereNotIn('state', [EngineCommand::CONFIRMED])->get(['id', 'command', 'state', 'attempts'])->toArray(),
        ];
    }

    /** @return array<string,mixed> */
    public function taskSummary(TaskProjection $t, User $user, bool $withInstance = true): array
    {
        $summary = [
            'id' => $t->id,
            'name' => $t->name,
            'stageKey' => $t->stage_key,
            'status' => $t->status,
            'candidateGroup' => $t->candidate_group,
            'candidateGroupName' => $this->groupName($t->candidate_group),
            'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'completedBy' => $t->completedByUser?->name,
            'dueAt' => $t->due_at?->toIso8601String(),
            'createdAt' => ($t->engine_created_at ?? $t->created_at)?->toIso8601String(),
            'completedAt' => $t->completed_at?->toIso8601String(),
            'ageHours' => (int) ($t->engine_created_at ?? $t->created_at)->diffInHours($t->completed_at ?? now()),
            'overdue' => $t->isActive() && $t->due_at !== null && $t->due_at->isPast(),
            'rowVersion' => $t->row_version,
            'canWork' => Access::canWork($user, $t),
            'canClaim' => Access::canClaim($user, $t),
            'canRelease' => $t->status === TaskProjection::CLAIMED && $t->candidate_group !== null && ($t->assignee_id === $user->id || Permissions::allows($user, Permissions::PROCESSES_MANAGE)),
            'canReassign' => in_array($t->status, [TaskProjection::OPEN, TaskProjection::CLAIMED], true) && Permissions::allows($user, Permissions::PROCESSES_MANAGE),
        ];
        if ($withInstance) {
            $i = $t->instance;
            $summary['instance'] = [
                'id' => $i->id,
                'number' => $i->number,
                'flowKey' => $i->catalog->key,
                'flowName' => $i->catalog->name,
                'requesterName' => $i->requester_name,
                'requesterArea' => $i->requester_area,
                'startedAt' => $i->started_at->toIso8601String(),
                'status' => $i->status,
            ];
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    public function taskDetail(TaskProjection $t, User $user): array
    {
        $t->load('instance.catalog', 'stage.form', 'assignee');
        $instance = $t->instance;
        $stage = $t->stage;
        $form = $stage->form;

        $ctx = new SubmissionContext(
            user: $user,
            instance: $instance,
            contextData: $instance->data ?? [],
            instanceInfo: ['number' => $instance->number, 'requesterName' => $instance->requester_name, 'requesterArea' => $instance->requester_area, 'startedAt' => $instance->started_at->toIso8601String()],
            preview: true,
        );
        $prefill = $form ? $this->processor->prefill($form->schema, $ctx) : [];
        $draft = TaskDraft::where('task_projection_id', $t->id)->where('user_id', $user->id)->first();
        $submission = $t->status === TaskProjection::COMPLETED || $t->status === TaskProjection::COMPLETING
            ? FormSubmission::where('task_projection_id', $t->id)->latest('created_at')->first() : null;
        $mode = $stage->configValue('actionPlan.mode');

        return [
            ...$this->taskSummary($t, $user),
            'description' => $stage->description,
            'form' => $form ? ['id' => $form->id, 'formKey' => $form->form_key, 'title' => $form->title, 'schema' => $form->schema] : null,
            'data' => $submission->payload ?? $prefill,
            'draft' => $draft?->payload,
            'submission' => $submission ? ['id' => $submission->id, 'authorName' => $submission->author_name, 'createdAt' => $submission->created_at->toIso8601String(), 'revision' => $submission->revision] : null,
            'actionPlan' => $mode ? ['mode' => $mode, 'actions' => $this->actions->present($instance)] : null,
            'attachments' => $instance->attachments()->where('task_projection_id', $t->id)->get()->map(fn ($a) => AttachmentService::present($a))->all(),
            'timeline' => $this->timeline($instance),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function timeline(ProcessInstance $i): array
    {
        $events = [[
            'at' => $i->started_at->toIso8601String(), 'type' => 'started',
            'title' => "Solicitação {$i->number} aberta", 'detail' => trim($i->requester_name.($i->requester_area ? ' · '.$i->requester_area : '')),
        ]];
        foreach ($i->tasks()->with('assignee', 'completedByUser')->get() as $t) {
            $events[] = [
                'at' => ($t->engine_created_at ?? $t->created_at)->toIso8601String(), 'type' => 'task_created', 'taskId' => $t->id,
                'title' => "Etapa \"{$t->name}\" disponível", 'detail' => $t->candidate_group ? 'Grupo: '.$this->groupName($t->candidate_group) : 'Responsável direto',
            ];
            if ($t->status === TaskProjection::COMPLETED || $t->status === TaskProjection::COMPLETING) {
                $events[] = [
                    'at' => ($t->completed_at ?? $t->updated_at)->toIso8601String(), 'type' => $t->status === TaskProjection::COMPLETING ? 'task_processing' : 'task_completed', 'taskId' => $t->id,
                    'title' => $t->status === TaskProjection::COMPLETING ? "Etapa \"{$t->name}\": processando conclusão" : "Etapa \"{$t->name}\" concluída",
                    'detail' => $t->completedByUser->name ?? '',
                ];
            } elseif ($t->status === TaskProjection::CANCELLED) {
                $events[] = ['at' => $t->updated_at->toIso8601String(), 'type' => 'task_cancelled', 'taskId' => $t->id, 'title' => "Etapa \"{$t->name}\" cancelada", 'detail' => ''];
            }
        }
        $labels = [
            'task.assignment_fallback' => 'Responsável indisponível: tarefa enviada ao grupo de contingência',
            'task.claimed' => 'Tarefa assumida', 'task.released' => 'Tarefa liberada', 'task.reassigned' => 'Tarefa reatribuída',
            'supplier_action.completed' => 'Ação do plano concluída', 'integration.completed' => 'Integração executada', 'integration.failed' => 'Falha de integração (nova tentativa agendada)',
            'engine.command_failed' => 'Falha ao comunicar com o engine',
        ];
        foreach (AuditEvent::where('process_instance_id', $i->id)->whereIn('action', array_keys($labels))->orderBy('occurred_at')->get() as $e) {
            $events[] = ['at' => $e->occurred_at->toIso8601String(), 'type' => $e->action, 'title' => $labels[$e->action], 'detail' => $e->actor_name ?? ''];
        }
        if ($i->ended_at) {
            $events[] = ['at' => $i->ended_at->toIso8601String(), 'type' => 'ended', 'title' => 'Solicitação encerrada', 'detail' => StageCatalog::END_RESULTS[$i->result] ?? (string) $i->result];
        }
        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $events;
    }

    public function groupName(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }
        $this->groupNames ??= BpmGroup::pluck('name', 'key')->all();

        return $this->groupNames[$key] ?? $key;
    }
}
