<?php

namespace App\Engine;

use App\Audit\AuditContext;
use App\Models\EngineCommand;
use App\Models\FormSubmission;
use App\Models\ProcessInstance;
use App\Models\ProcessStageVersion;
use App\Models\ProcessTransitionVersion;
use App\Models\TaskProjection;
use App\Notifications\Notifier;
use App\Support\AuditLogger;
use App\Support\Correlation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza a projeção local (task_projection / status da instância) com o engine.
 * Idempotente: pode ser chamado após cada comando confirmado e pelo reconciliador.
 */
class ProjectionSync
{
    public function __construct(
        private readonly FlowableRestClient $engine,
        private readonly EngineCommandDispatcher $dispatcher,
        private readonly Notifier $notifier,
    ) {}

    public function syncInstance(ProcessInstance $instance): void
    {
        if (! $instance->engine_process_instance_id || in_array($instance->status, [ProcessInstance::COMPLETED, ProcessInstance::CANCELLED], true)) {
            return;
        }
        $engineTasks = $this->engine->activeTasks($instance->engine_process_instance_id);
        $stages = ProcessStageVersion::where('definition_version_id', $instance->definition_version_id)->get()->keyBy('element_id');

        $created = [];
        $seen = [];
        DB::transaction(function () use ($instance, $engineTasks, $stages, &$created, &$seen) {
            foreach ($engineTasks as $et) {
                $seen[] = (string) $et['id'];
                if (TaskProjection::where('engine_task_id', $et['id'])->exists()) {
                    continue;
                }
                $stage = $stages[$et['taskDefinitionKey']] ?? null;
                if (! $stage) {
                    continue;
                }
                $task = TaskProjection::create([
                    'process_instance_id' => $instance->id,
                    'engine_task_id' => (string) $et['id'],
                    'stage_version_id' => $stage->id,
                    'stage_key' => $stage->stage_key,
                    'name' => $stage->name,
                    'candidate_group' => $stage->lane,
                    'status' => TaskProjection::OPEN,
                    'due_at' => ! empty($et['dueDate']) ? CarbonImmutable::parse($et['dueDate']) : null,
                    'engine_created_at' => ! empty($et['createTime']) ? CarbonImmutable::parse($et['createTime']) : now(),
                ]);
                $created[] = $task;
                // Entrada na etapa: o engine já confirmou a tarefa (nunca antes da transição BPMN).
                $event = AuditContext::as('engine/reconciliador', fn () => AuditLogger::record('task.created', 'task_projection', $task->id, ['group' => $task->candidate_group, 'due' => $task->due_at?->toIso8601String()], $instance->id, context: ['object_name' => $task->name]));
                foreach ($this->decisionsLeadingTo($stage, $stages) as $decision) {
                    AuditContext::as('engine/reconciliador', fn () => AuditLogger::record('instance.branch_taken', 'process_stage_version', $decision['gatewayId'], ['gateway' => $decision['gateway'], 'path' => $decision['path'], 'target' => $stage->name, 'ambiguous' => $decision['ambiguous']], $instance->id, context: ['stage_key' => $stage->stage_key, 'task_id' => $task->id, 'causation_id' => $event->id]));
                }
                if (! $this->isOpeningTask($instance, $task)) {
                    // mesma transação da projeção: outbox de e-mail (idempotente por instância+tarefa+destinatário)
                    AuditContext::as('engine/reconciliador', fn () => $this->notifier->taskAssigned($task, 'created', $event->id));
                }
            }

            // Tarefas que sumiram do runtime sem passar pelo nosso COMPLETE (ex.: cancelamento no engine).
            $stale = TaskProjection::where('process_instance_id', $instance->id)
                ->whereIn('status', [TaskProjection::OPEN, TaskProjection::CLAIMED])
                ->whereNotIn('engine_task_id', $seen ?: ['-'])
                ->get();
            foreach ($stale as $task) {
                $historic = $this->engine->historicTask((string) $task->engine_task_id);
                $completed = $historic && ! empty($historic['endTime']) && empty($historic['deleteReason']);
                $task->update(['status' => $completed ? TaskProjection::COMPLETED : TaskProjection::CANCELLED, 'completed_at' => now(), 'row_version' => $task->row_version + 1]);
                if (! $completed) {
                    AuditContext::as('engine/reconciliador', fn () => AuditLogger::record('task.cancelled', 'task_projection', $task->id, ['reason' => $historic['deleteReason'] ?? 'removida do engine'], $instance->id));
                }
            }
        });

        foreach ($created as $task) {
            $this->completeOpeningTask($instance, $task);
        }

        if ($engineTasks === [] && ! TaskProjection::where('process_instance_id', $instance->id)->where('status', TaskProjection::COMPLETING)->exists()) {
            $this->checkEnded($instance, $stages);
        }
        $instance->forceFill(['synced_at' => now()])->saveQuietly();
    }

    /**
     * A submissão de abertura foi gravada antes do start; quando o engine cria a primeira
     * tarefa humana, ela é concluída com essa submissão (comando idempotente via outbox).
     */
    private function completeOpeningTask(ProcessInstance $instance, TaskProjection $task): bool
    {
        $submission = FormSubmission::where('process_instance_id', $instance->id)
            ->whereNull('task_projection_id')
            ->where('stage_version_id', $task->stage_version_id)
            ->first();
        if (! $submission) {
            return false;
        }

        $command = DB::transaction(function () use ($instance, $task, $submission) {
            $submission->update(['task_projection_id' => $task->id]);
            $task->update([
                'status' => TaskProjection::COMPLETING,
                'assignee_id' => $instance->requester_id,
                'claimed_at' => now(),
                'completed_by' => $instance->requester_id,
                'row_version' => $task->row_version + 1,
            ]);

            return EngineCommand::firstOrCreate(
                ['idempotency_key' => 'complete:'.$task->engine_task_id],
                [
                    'command' => EngineCommand::COMPLETE,
                    'process_instance_id' => $instance->id,
                    'task_projection_id' => $task->id,
                    // variáveis já enviadas no start
                    'payload' => ['engineTaskId' => $task->engine_task_id, 'assignee' => $instance->requester_id, 'variables' => []],
                    'state' => EngineCommand::PENDING,
                    'correlation_id' => Correlation::id(),
                    'available_at' => now(),
                ],
            );
        });
        $this->dispatcher->dispatch($command);

        return true;
    }

    private function isOpeningTask(ProcessInstance $instance, TaskProjection $task): bool
    {
        return FormSubmission::where('process_instance_id', $instance->id)->whereNull('task_projection_id')->where('stage_version_id', $task->stage_version_id)->exists();
    }

    /**
     * Decisões (gateway exclusivo) que levam diretamente à etapa ativada, atravessando integrações e
     * timers. Só a etapa efetivamente criada pelo engine é considerada — caminhos não escolhidos nunca.
     *
     * @param  Collection<string, ProcessStageVersion>  $stages
     * @return list<array{gatewayId:string, gateway:string, path:string, ambiguous:bool}>
     */
    private function decisionsLeadingTo(ProcessStageVersion $stage, Collection $stages): array
    {
        $byId = $stages->keyBy('id');
        $transitions = ProcessTransitionVersion::where('definition_version_id', $stage->definition_version_id)->get();
        $found = [];
        $walk = function (ProcessStageVersion $target, int $depth) use (&$walk, &$found, $transitions, $byId) {
            if ($depth > 4) {
                return;
            }
            foreach ($transitions->where('target_stage_id', $target->id) as $t) {
                $source = $byId[$t->source_stage_id] ?? null;
                if (! $source) {
                    continue;
                }
                if ($source->stage_type === ProcessStageVersion::EXCLUSIVE_GATEWAY) {
                    $found[] = ['gatewayId' => $source->id, 'gateway' => $source->name, 'path' => $t->label ?: ($t->is_default ? 'caminho padrão' : 'para '.$target->name), 'ambiguous' => false];
                } elseif (in_array($source->stage_type, [ProcessStageVersion::SERVICE_TASK, ProcessStageVersion::TIMER], true)) {
                    $walk($source, $depth + 1);
                }
            }
        };
        $walk($stage, 0);
        if (count($found) > 1) {
            foreach ($found as &$f) {
                $f['ambiguous'] = true; // etapa alcançável por mais de uma decisão
            }
        }

        return $found;
    }

    /** @param Collection<string, ProcessStageVersion> $stages */
    private function checkEnded(ProcessInstance $instance, $stages): void
    {
        $historic = $this->engine->historicProcess((string) $instance->engine_process_instance_id);
        if (! $historic || empty($historic['endTime'])) {
            return;
        }
        $endStage = $stages[$historic['endActivityId'] ?? ''] ?? null;
        $result = $historic['deleteReason'] ? 'cancelado' : ($endStage?->configValue('result', 'concluido') ?? 'concluido');

        $instance->update([
            'status' => $historic['deleteReason'] ? ProcessInstance::CANCELLED : ProcessInstance::COMPLETED,
            'result' => $result,
            'end_stage_key' => $endStage?->stage_key,
            'ended_at' => CarbonImmutable::parse($historic['endTime']),
        ]);
        TaskProjection::where('process_instance_id', $instance->id)->whereIn('status', [TaskProjection::OPEN, TaskProjection::CLAIMED])
            ->update(['status' => TaskProjection::CANCELLED]);
        AuditLogger::record('instance.ended', 'process_instance', $instance->id, ['result' => $result, 'endStage' => $endStage?->stage_key], $instance->id);
        $this->notifier->instanceEnded($instance, (array) ($endStage?->configValue('notify', []) ?? []));
    }
}
