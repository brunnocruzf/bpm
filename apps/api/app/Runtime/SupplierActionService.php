<?php

namespace App\Runtime;

use App\Models\Attachment;
use App\Models\FormSubmission;
use App\Models\ProcessInstance;
use App\Models\SupplierAction;
use App\Models\TaskProjection;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Plano de ação do fornecedor (atividades 30 e 30.1):
 *  - "capture": as linhas do repetidor viram ações individuais (cada revisão substitui o plano anterior,
 *    preservando o histórico como "superseded");
 *  - "execute": cada ação é concluída individualmente com evidência; a tarefa só finaliza
 *    quando todas as ações estiverem concluídas.
 */
class SupplierActionService
{
    /** @param array<mixed> $rows linhas do repetidor já validadas */
    public function capture(ProcessInstance $instance, FormSubmission $submission, array $rows): void
    {
        SupplierAction::where('process_instance_id', $instance->id)->whereIn('status', [SupplierAction::PENDING, SupplierAction::DONE])
            ->update(['status' => SupplierAction::SUPERSEDED]);

        foreach (array_values($rows) as $i => $row) {
            $due = null;
            if (! empty($row['prazo'])) {
                try {
                    $due = CarbonImmutable::parse((string) $row['prazo'])->toDateString();
                } catch (Throwable) {
                    $due = null;
                }
            }
            SupplierAction::create([
                'process_instance_id' => $instance->id,
                'source_submission_id' => $submission->id,
                'kind' => ($row['tipo'] ?? 'corretiva') === 'preventiva' ? 'preventiva' : 'corretiva',
                'action_text' => (string) ($row['acao'] ?? ''),
                'responsible' => (string) ($row['responsavel'] ?? ''),
                'due_date' => $due,
                'location' => isset($row['local']) ? (string) $row['local'] : null,
                'notes' => isset($row['observacoes']) ? (string) $row['observacoes'] : null,
                'status' => SupplierAction::PENDING,
                'position' => $i,
            ]);
        }
    }

    /** @param list<string> $attachmentIds */
    public function complete(User $user, TaskProjection $task, SupplierAction $action, ?string $notes, array $attachmentIds): SupplierAction
    {
        if (! Access::canWork($user, $task) || $task->stage?->configValue('actionPlan.mode') !== 'execute') {
            throw ApiProblem::forbidden('Esta tarefa não permite executar ações do plano.');
        }
        if ($action->process_instance_id !== $task->process_instance_id || $action->status === SupplierAction::SUPERSEDED) {
            throw ApiProblem::notFound('Ação não encontrada.');
        }
        if ($action->status === SupplierAction::DONE) {
            throw ApiProblem::conflict('Esta ação já foi concluída.', 'action_done');
        }

        return DB::transaction(function () use ($user, $task, $action, $notes, $attachmentIds) {
            $valid = Attachment::whereIn('id', $attachmentIds)->where('task_projection_id', $task->id)->where('uploader_id', $user->id)->pluck('id')->all();
            Attachment::whereIn('id', $valid)->update(['supplier_action_id' => $action->id]);
            $action->update([
                'status' => SupplierAction::DONE,
                'completed_by' => $user->id,
                'completed_at' => now(),
                'completion_notes' => $notes !== null ? mb_substr(strip_tags($notes), 0, 4000) : null,
            ]);
            AuditLogger::record('supplier_action.completed', 'supplier_action', $action->id, ['evidences' => count($valid)], $task->process_instance_id);

            return $action->fresh();
        });
    }

    public function assertAllDone(ProcessInstance $instance): void
    {
        $actions = SupplierAction::where('process_instance_id', $instance->id)->where('status', '!=', SupplierAction::SUPERSEDED)->get();
        if ($actions->isEmpty()) {
            throw ApiProblem::validation(['acoes' => ['Não há plano de ação registrado para executar.']]);
        }
        $pending = $actions->where('status', SupplierAction::PENDING)->count();
        if ($pending > 0) {
            throw ApiProblem::validation(['acoes' => ["Conclua todas as ações do plano antes de finalizar ($pending pendente(s))."]]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function present(ProcessInstance $instance, bool $includeSuperseded = false): array
    {
        $query = SupplierAction::where('process_instance_id', $instance->id)->with('evidences', 'completedByUser')->orderBy('created_at')->orderBy('position');
        if (! $includeSuperseded) {
            $query->where('status', '!=', SupplierAction::SUPERSEDED);
        }

        return $query->get()->map(fn (SupplierAction $a) => [
            'id' => $a->id,
            'kind' => $a->kind,
            'action' => $a->action_text,
            'responsible' => $a->responsible,
            'dueDate' => $a->due_date?->toDateString(),
            'location' => $a->location,
            'notes' => $a->notes,
            'status' => $a->status,
            'completedBy' => $a->completedByUser?->name,
            'completedAt' => $a->completed_at?->toIso8601String(),
            'completionNotes' => $a->completion_notes,
            'evidences' => $a->evidences->map(fn ($e) => AttachmentService::present($e))->all(),
        ])->all();
    }
}
