<?php

namespace App\Runtime;

use App\Authorization\Permissions;
use App\Engine\EngineCommandDispatcher;
use App\Forms\SubmissionContext;
use App\Forms\SubmissionProcessor;
use App\Models\EngineCommand;
use App\Models\FormSubmission;
use App\Models\ProcessInstance;
use App\Models\TaskDraft;
use App\Models\TaskProjection;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use App\Support\Correlation;
use Illuminate\Support\Facades\DB;

/**
 * Transições de tarefa: claim, liberar, reatribuir, rascunho e conclusão.
 * Conclusão: valida atribuição, versão (ETag), payload; grava submissão, contexto,
 * auditoria e comando COMPLETE na mesma transação. Sem atomicidade distribuída:
 * a tarefa fica "completing" até o engine confirmar.
 */
class TaskService
{
    public function __construct(
        private readonly SubmissionProcessor $processor,
        private readonly AttachmentService $attachments,
        private readonly SupplierActionService $actions,
        private readonly EngineCommandDispatcher $dispatcher,
        private readonly AssignmentResolver $assignments,
    ) {}

    public function claim(User $user, TaskProjection $task): TaskProjection
    {
        return DB::transaction(function () use ($user, $task) {
            $task = TaskProjection::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if ($task->assignee_id === $user->id && $task->status === TaskProjection::CLAIMED) {
                return $task;
            }
            if ($task->status !== TaskProjection::OPEN || $task->assignee_id !== null) {
                throw ApiProblem::conflict('Esta tarefa já foi assumida por outra pessoa ou não está mais disponível.', 'task_unavailable');
            }
            if (! Access::canClaim($user, $task)) {
                throw ApiProblem::forbidden('Você não pertence ao grupo responsável por esta tarefa.');
            }
            $task->update(['status' => TaskProjection::CLAIMED, 'assignee_id' => $user->id, 'claimed_at' => now(), 'row_version' => $task->row_version + 1]);
            AuditLogger::record('task.claimed', 'task_projection', $task->id, [], $task->process_instance_id);

            return $task;
        });
    }

    public function release(User $user, TaskProjection $task): TaskProjection
    {
        return DB::transaction(function () use ($user, $task) {
            $task = TaskProjection::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if ($task->status !== TaskProjection::CLAIMED || ($task->assignee_id !== $user->id && ! Permissions::allows($user, Permissions::PROCESSES_MANAGE))) {
                throw ApiProblem::conflict('Somente quem assumiu a tarefa pode liberá-la.', 'task_not_claimed');
            }
            if ($task->candidate_group === null) {
                throw ApiProblem::conflict('Esta tarefa foi atribuída diretamente e não tem grupo para onde voltar. Peça a reatribuição a um administrador de processos.', 'task_directly_assigned');
            }
            $task->update(['status' => TaskProjection::OPEN, 'assignee_id' => null, 'claimed_at' => null, 'row_version' => $task->row_version + 1]);
            AuditLogger::record('task.released', 'task_projection', $task->id, [], $task->process_instance_id);

            return $task;
        });
    }

    public function reassign(User $user, TaskProjection $task, string $targetUserId, string $justification): TaskProjection
    {
        if (! Permissions::allows($user, Permissions::PROCESSES_MANAGE)) {
            throw ApiProblem::forbidden('Reatribuição exige a permissão de administrar processos.');
        }
        $justification = trim(strip_tags($justification));
        if (mb_strlen($justification) < 10) {
            throw ApiProblem::validation(['justification' => ['Informe uma justificativa (mínimo 10 caracteres).']]);
        }
        if ($task->candidate_group === null) {
            // atribuição direta: sem grupo para conferir, vale a elegibilidade da regra da etapa
            $reason = $this->assignments->ineligibility($targetUserId, $task->stage, $task->instance);
            if ($reason !== null) {
                throw ApiProblem::validation(['userId' => ['Não é possível reatribuir: '.AssignmentResolver::REASONS[$reason]]]);
            }
            $target = User::findOrFail($targetUserId);
        } else {
            $target = User::where('id', $targetUserId)->where('is_active', true)->first();
            if (! $target || ! $target->inGroup($task->candidate_group) || ! $target->hasRole('task_worker')) {
                throw ApiProblem::validation(['userId' => ['O usuário de destino precisa pertencer ao grupo responsável.']]);
            }
        }

        return DB::transaction(function () use ($task, $target, $justification) {
            $task = TaskProjection::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if (! in_array($task->status, [TaskProjection::OPEN, TaskProjection::CLAIMED], true)) {
                throw ApiProblem::conflict('A tarefa não está mais aberta.', 'task_unavailable');
            }
            $previous = $task->assignee_id;
            $task->update(['status' => TaskProjection::CLAIMED, 'assignee_id' => $target->id, 'claimed_at' => now(), 'row_version' => $task->row_version + 1]);
            $event = AuditLogger::record('task.reassigned', 'task_projection', $task->id, ['from' => $previous, 'to' => $target->id, 'toName' => $target->name, 'justification' => $justification], $task->process_instance_id);
            // política da etapa: avisar o novo responsável (idempotente por tarefa + destinatário)
            app(Notifier::class)->taskAssigned($task, 'reassigned', $event->id);

            return $task;
        });
    }

    /** @param array<string,mixed> $data */
    public function saveDraft(User $user, TaskProjection $task, array $data): void
    {
        if (! Access::canWork($user, $task)) {
            throw ApiProblem::forbidden();
        }
        if (strlen((string) json_encode($data)) > 256 * 1024) {
            throw ApiProblem::validation(['data' => ['Rascunho muito grande.']]);
        }
        TaskDraft::updateOrCreate(['task_projection_id' => $task->id, 'user_id' => $user->id], ['payload' => $data]);
    }

    /** @param array<string,mixed> $data */
    public function complete(User $user, TaskProjection $task, array $data, ?int $rowVersion): TaskProjection
    {
        [$task, $command] = DB::transaction(function () use ($user, $task, $data, $rowVersion) {
            $task = TaskProjection::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if (! in_array($task->status, [TaskProjection::OPEN, TaskProjection::CLAIMED], true)) {
                throw ApiProblem::conflict('Esta tarefa já foi concluída ou está em processamento.', 'task_unavailable');
            }
            if ($rowVersion !== null && $rowVersion !== $task->row_version) {
                throw ApiProblem::conflict('A tarefa foi alterada desde que você a abriu. Recarregue a página.', 'stale_task', ['rowVersion' => $task->row_version]);
            }
            if (! Access::canWork($user, $task)) {
                throw ApiProblem::forbidden('Você não pode concluir esta tarefa (assuma-a ou verifique seu grupo).');
            }

            $instance = ProcessInstance::whereKey($task->process_instance_id)->lockForUpdate()->firstOrFail();
            $stage = $task->stage()->with('form')->firstOrFail();
            if (! $stage->form) {
                throw ApiProblem::conflict('Etapa sem formulário associado.', 'form_missing');
            }
            if ($stage->configValue('actionPlan.mode') === 'execute') {
                $this->actions->assertAllDone($instance);
            }

            $ctx = new SubmissionContext(
                user: $user,
                instance: $instance,
                contextData: $instance->data ?? [],
                instanceInfo: ['number' => $instance->number, 'requesterName' => $instance->requester_name, 'requesterArea' => $instance->requester_area, 'startedAt' => $instance->started_at->toIso8601String()],
                resolveAttachment: $this->attachments->resolver($user, $instance),
            );
            $processed = $this->processor->process($stage->form->schema, $data, $ctx);
            $this->assignments->assertSubmission($instance->definitionVersion, $processed, $ctx);

            $revision = FormSubmission::where('process_instance_id', $instance->id)->where('stage_version_id', $stage->id)->count() + 1;
            $submission = FormSubmission::create([
                'process_instance_id' => $instance->id,
                'task_projection_id' => $task->id,
                'stage_version_id' => $stage->id,
                'form_schema_version_id' => $stage->form->id,
                'form_schema_hash' => $stage->form->hash,
                'payload' => $processed->data,
                'metadata' => $processed->metadata,
                'author_id' => $user->id,
                'author_name' => $user->name,
                'revision' => $revision,
                'created_at' => now('UTC'),
            ]);
            $instance->update(['data' => array_merge($instance->data ?? [], $processed->contextPatch())]);
            $this->attachments->linkToInstance($instance, $processed->data);

            if ($stage->configValue('actionPlan.mode') === 'capture') {
                $field = (string) $stage->configValue('actionPlan.field', 'acoes');
                $this->actions->capture($instance, $submission, (array) ($processed->data[$field] ?? []));
            }

            $task->update([
                'status' => TaskProjection::COMPLETING,
                'assignee_id' => $task->assignee_id ?? $user->id,
                'claimed_at' => $task->claimed_at ?? now(),
                'completed_by' => $user->id,
                'completed_at' => now(),
                'row_version' => $task->row_version + 1,
            ]);
            TaskDraft::where('task_projection_id', $task->id)->delete();

            $event = AuditLogger::record('task.completed', 'task_projection', $task->id, [
                'submission' => $submission->id,
                'revision' => $revision,
                'fields' => array_keys($processed->data),
            ], $instance->id);

            $command = EngineCommand::create([
                'causation_id' => $event->id,
                'command' => EngineCommand::COMPLETE,
                // por submissão: após falha definitiva a tarefa reabre e uma nova conclusão gera novo comando
                'idempotency_key' => 'complete:'.$task->engine_task_id.':'.$submission->id,
                'process_instance_id' => $instance->id,
                'task_projection_id' => $task->id,
                'payload' => ['engineTaskId' => $task->engine_task_id, 'assignee' => $user->id, 'variables' => $processed->engineVariables()],
                'state' => EngineCommand::PENDING,
                'correlation_id' => Correlation::id(),
                'available_at' => now(),
            ]);

            return [$task, $command];
        });

        $this->dispatcher->dispatch($command);

        return $task->fresh();
    }
}
