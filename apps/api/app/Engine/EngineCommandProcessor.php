<?php

namespace App\Engine;

use App\Audit\AuditContext;
use App\Models\EngineCommand;
use App\Models\FormSubmission;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessInstance;
use App\Models\TaskProjection;
use App\Notifications\Notifier;
use App\Support\AuditLogger;
use App\Support\Correlation;
use App\Support\Masker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processa comandos do outbox: PENDING → SENDING → CONFIRMED, ou
 * RECONCILIATION_REQUIRED / FAILED. Timeout é ambíguo: antes de repetir,
 * consulta o engine para saber se o comando já foi aplicado (sem duplicar
 * instância nem conclusão). Laravel e Flowable não compartilham transação ACID.
 */
class EngineCommandProcessor
{
    public function __construct(
        private readonly FlowableRestClient $engine,
        private readonly ProjectionSync $sync,
        private readonly EngineCommandDispatcher $dispatcher,
        private readonly Notifier $notifier,
    ) {}

    public function process(string $commandId): void
    {
        $command = $this->claim($commandId);
        if (! $command) {
            return;
        }
        Correlation::set($command->correlation_id);
        AuditContext::as('worker:outbox do engine', fn () => $this->handle($command), $command->causation_id);
    }

    private function handle(EngineCommand $command): void
    {

        if ($this->hasPendingPredecessor($command)) {
            $command->update(['state' => EngineCommand::PENDING, 'attempts' => max(0, $command->attempts - 1), 'locked_until' => null, 'available_at' => now()->addSeconds(3)]);
            $this->dispatcher->dispatch($command, 3);

            return;
        }

        try {
            $result = $command->attempts > 1 ? $this->reconcile($command) : null;
            $result ??= $this->send($command);
            $this->confirm($command, $result);
        } catch (EngineRejected $e) {
            $this->fail($command, $e->getMessage());
        } catch (Throwable $e) {
            // EngineUnavailable ou falha local após envio: estado ambíguo → reconciliar depois.
            $this->markAmbiguous($command, $e);
        }
    }

    /** Claim atômico: evita processamento concorrente do mesmo comando. */
    private function claim(string $id): ?EngineCommand
    {
        $now = now();
        $updated = EngineCommand::whereKey($id)
            ->whereIn('state', [EngineCommand::PENDING, EngineCommand::RECONCILIATION_REQUIRED, EngineCommand::SENDING])
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', $now))
            ->update([
                'state' => EngineCommand::SENDING,
                'locked_until' => $now->copy()->addSeconds(90),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);

        return $updated === 1 ? EngineCommand::find($id) : null;
    }

    private function hasPendingPredecessor(EngineCommand $c): bool
    {
        if (! $c->process_instance_id) {
            return false;
        }

        return EngineCommand::where('process_instance_id', $c->process_instance_id)
            ->where('id', '!=', $c->id)
            ->where('created_at', '<', $c->created_at)
            ->whereNotIn('state', [EngineCommand::CONFIRMED, EngineCommand::FAILED])
            ->exists();
    }

    /** @return array<string,mixed> */
    private function send(EngineCommand $c): array
    {
        return match ($c->command) {
            EngineCommand::DEPLOY => (function () use ($c) {
                $version = ProcessDefinitionVersion::findOrFail($c->definition_version_id);

                return $this->engine->deploy($c->payload['deploymentName'], $c->payload['fileName'], (string) $version->bpmn_xml);
            })(),
            EngineCommand::START => (function () use ($c) {
                $instance = ProcessInstance::with('definitionVersion')->findOrFail($c->process_instance_id);
                $variables = array_merge((array) ($c->payload['variables'] ?? []), [
                    'initiator' => $instance->requester_id,
                    'tmsaInstanceId' => $instance->id,
                    'tmsaInstanceNumber' => $instance->number,
                ]);

                return ['processInstanceId' => $this->engine->startProcess((string) $instance->definitionVersion->engine_definition_id, $instance->id, $variables)];
            })(),
            EngineCommand::COMPLETE => (function () use ($c) {
                $taskId = (string) $c->payload['engineTaskId'];
                if (! empty($c->payload['assignee'])) {
                    $this->engine->assignTask($taskId, (string) $c->payload['assignee']);
                }
                if (! $this->engine->completeTask($taskId, (array) ($c->payload['variables'] ?? []))) {
                    // 404: pode já ter sido concluída (resposta perdida) → reconcilia
                    return $this->reconcile($c) ?? throw new EngineRejected('Tarefa não encontrada no engine.', 404);
                }

                return ['completed' => true];
            })(),
            default => throw new EngineRejected("Comando desconhecido {$c->command}."),
        };
    }

    /** @return array<string,mixed>|null null = ainda não aplicado no engine */
    private function reconcile(EngineCommand $c): ?array
    {
        return match ($c->command) {
            EngineCommand::DEPLOY => $this->engine->findDeploymentByName($c->payload['deploymentName']),
            EngineCommand::START => ($found = $this->engine->findProcessByBusinessKey((string) $c->process_instance_id)) ? ['processInstanceId' => $found['id'], 'reconciled' => true] : null,
            EngineCommand::COMPLETE => (function () use ($c) {
                $taskId = (string) $c->payload['engineTaskId'];
                if ($this->engine->getTask($taskId) !== null) {
                    return null;
                }
                $historic = $this->engine->historicTask($taskId);
                if ($historic && ! empty($historic['endTime'])) {
                    return ['completed' => true, 'reconciled' => true];
                }
                throw new EngineRejected('Tarefa inexistente no engine; reconciliação manual necessária.', 404);
            })(),
            default => null,
        };
    }

    /** @param array<string,mixed> $result */
    private function confirm(EngineCommand $c, array $result): void
    {
        $instance = null;
        DB::transaction(function () use ($c, $result, &$instance) {
            $c->update(['state' => EngineCommand::CONFIRMED, 'response' => Masker::mask($result), 'confirmed_at' => now(), 'locked_until' => null, 'last_error' => null]);

            switch ($c->command) {
                case EngineCommand::DEPLOY:
                    ProcessDefinitionVersion::whereKey($c->definition_version_id)->update([
                        'engine_deployment_id' => $result['deploymentId'],
                        'engine_definition_id' => $result['definitionId'],
                    ]);
                    AuditLogger::record('engine.deployed', 'process_definition_version', $c->definition_version_id, ['definitionId' => $result['definitionId']], context: ['outbox_id' => $c->id, 'idempotency_key' => $c->idempotency_key]);
                    break;
                case EngineCommand::START:
                    $instance = ProcessInstance::findOrFail($c->process_instance_id);
                    $instance->update(['engine_process_instance_id' => $result['processInstanceId'], 'status' => ProcessInstance::RUNNING]);
                    AuditLogger::record('engine.instance_started', 'process_instance', $instance->id, ['reconciled' => $result['reconciled'] ?? false], $instance->id, context: ['outbox_id' => $c->id, 'idempotency_key' => $c->idempotency_key]);
                    break;
                case EngineCommand::COMPLETE:
                    $task = TaskProjection::findOrFail($c->task_projection_id);
                    $task->update(['status' => TaskProjection::COMPLETED, 'completed_at' => $task->completed_at ?? now(), 'row_version' => $task->row_version + 1]);
                    $instance = $task->instance;
                    AuditLogger::record('engine.task_completed', 'task_projection', $task->id, ['reconciled' => $result['reconciled'] ?? false], $task->process_instance_id, context: ['outbox_id' => $c->id, 'idempotency_key' => $c->idempotency_key]);
                    break;
            }
        });

        if ($c->command === EngineCommand::COMPLETE) {
            $task = TaskProjection::with('stage', 'instance.catalog')->find($c->task_projection_id);
            $groups = (array) ($task?->stage?->configValue('notifyOnComplete', []) ?? []);
            if ($task && $groups) {
                $this->notifier->stageCompleted($task, $groups);
            }
        }

        if ($instance) {
            try {
                $this->sync->syncInstance($instance->fresh());
            } catch (Throwable $e) {
                // O comando está confirmado; a projeção será corrigida pelo reconciliador.
                Log::warning('Falha ao sincronizar projeção após confirmação', ['instance' => $instance->id, 'error' => Masker::error($e)]);
            }
        }
    }

    private function markAmbiguous(EngineCommand $c, Throwable $e): void
    {
        $max = (int) config('bpm.engine.outbox_max_attempts', 10);
        if ($c->attempts >= $max) {
            $this->fail($c, 'Limite de tentativas: '.Masker::error($e));

            return;
        }
        $delay = min(600, 5 * (2 ** max(0, $c->attempts - 1)));
        $c->update([
            'state' => EngineCommand::RECONCILIATION_REQUIRED,
            'last_error' => Masker::error($e),
            'locked_until' => null,
            'available_at' => now()->addSeconds($delay),
        ]);
        Log::warning('Comando de engine em reconciliação', ['command' => $c->id, 'type' => $c->command, 'attempt' => $c->attempts]);
        $this->dispatcher->dispatch($c, $delay);
    }

    private function fail(EngineCommand $c, string $message): void
    {
        DB::transaction(function () use ($c, $message) {
            $c->update(['state' => EngineCommand::FAILED, 'last_error' => Masker::error($message), 'locked_until' => null]);
            if ($c->command === EngineCommand::START && $c->process_instance_id) {
                ProcessInstance::whereKey($c->process_instance_id)->update(['status' => ProcessInstance::ERROR]);
            }
            if ($c->command === EngineCommand::COMPLETE && $c->task_projection_id) {
                // reabre a tarefa para nova tentativa; a submissão permanece no histórico
                $task = TaskProjection::find($c->task_projection_id);
                if ($task && $task->status === TaskProjection::COMPLETING) {
                    $task->update(['status' => $task->assignee_id ? TaskProjection::CLAIMED : TaskProjection::OPEN, 'completed_at' => null, 'completed_by' => null, 'row_version' => $task->row_version + 1]);
                }
            }
            AuditLogger::record('engine.command_failed', 'engine_command', $c->id, ['command' => $c->command, 'error' => $message], $c->process_instance_id, outcome: 'failure', context: ['outbox_id' => $c->id, 'task_id' => $c->task_projection_id]);
        });
        Log::error('Comando de engine falhou', ['command' => $c->id, 'type' => $c->command]);
    }

    /** Reenvio manual autorizado (tela de reconciliação). */
    public function retry(EngineCommand $c): void
    {
        if ($c->state !== EngineCommand::FAILED && $c->state !== EngineCommand::RECONCILIATION_REQUIRED) {
            return;
        }
        // attempts = 1 → o próximo claim incrementa para 2 e força reconciliação antes de reenviar.
        $c->update(['state' => EngineCommand::RECONCILIATION_REQUIRED, 'attempts' => 1, 'available_at' => now(), 'locked_until' => null]);
        if ($c->command === EngineCommand::COMPLETE && $c->task_projection_id) {
            TaskProjection::whereKey($c->task_projection_id)->whereIn('status', [TaskProjection::OPEN, TaskProjection::CLAIMED])->update(['status' => TaskProjection::COMPLETING]);
        }
        AuditLogger::record('engine.command_retry', 'engine_command', $c->id, ['command' => $c->command], $c->process_instance_id, context: ['outbox_id' => $c->id, 'task_id' => $c->task_projection_id]);
        $this->dispatcher->dispatch($c);
    }

    /** @return int quantidade de comandos despachados */
    public function dispatchDue(): int
    {
        $n = 0;
        $due = EngineCommand::whereIn('state', [EngineCommand::PENDING, EngineCommand::RECONCILIATION_REQUIRED])
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orWhere(fn ($q) => $q->where('state', EngineCommand::SENDING)->where('locked_until', '<', now()))
            ->orderBy('created_at')->limit(100)->get();
        foreach ($due as $command) {
            $this->process($command->id);
            $n++;
        }

        return $n;
    }

    public static function submissionForOpening(ProcessInstance $instance): ?FormSubmission
    {
        return FormSubmission::where('process_instance_id', $instance->id)->whereNull('task_projection_id')->orderBy('created_at')->first();
    }
}
