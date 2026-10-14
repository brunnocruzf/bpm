<?php

namespace App\Audit;

use App\Models\AuditEvent;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessInstance;
use App\Models\TaskProjection;
use App\Models\User;
use App\Support\Correlation;
use App\Support\Masker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Serviço central da trilha de auditoria funcional (separada dos logs técnicos).
 * - Grava na transação local corrente (quando chamado dentro de DB::transaction).
 * - Enriquecimento BPM (fluxo, versão, protocolo, processInstanceId do engine, etapa, tarefa).
 * - Redação central (Masker) de dados e diffs; nunca grava tokens, segredos ou corpos externos.
 * - Ator humano (oid/UPN) ou ator de sistema identificado (AuditContext).
 */
class AuditService
{
    /** @var array<string, array<string,mixed>> */
    private array $instanceMemo = [];

    /**
     * @param  array<string,mixed>  $data  metadados (redigidos)
     * @param  array<string,mixed>|null  $diff  ['campo' => ['from'=>..,'to'=>..]] (use AuditDiff::between)
     * @param  array<string,mixed>  $context  stage_key, task_id, form_schema_version_id, causation_id, idempotency_key, outbox_id, object_name, flow_key, definition_version
     */
    public function record(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $data = [],
        ?string $processInstanceId = null,
        ?User $actor = null,
        ?array $diff = null,
        string $outcome = 'success',
        array $context = [],
    ): AuditEvent {
        if (! AuditCatalog::known($action) && app()->runningUnitTests()) {
            throw new InvalidArgumentException("Evento de auditoria fora do catálogo: $action");
        }
        if (! in_array($outcome, ['success', 'failure', 'blocked'], true)) {
            $outcome = 'failure';
        }

        // contexto de sistema explícito (worker/engine) prevalece sobre o usuário da requisição
        /** @var User|null $actor */
        $actor ??= AuditContext::systemActor() !== null ? null : Auth::user();
        $bpm = $this->bpmContext($entityType, $entityId, $processInstanceId);

        $row = [
            'organization_key' => (string) config('bpm.organization.key', 'tmsa'),
            'category' => AuditCatalog::category($action),
            'action' => $action,
            'outcome' => $outcome,
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? 'user' : 'system',
            'actor_name' => $actor ? $actor->name : (AuditContext::systemActor() ?? 'sistema'),
            'actor_oid' => $actor?->entra_oid,
            'actor_upn' => $actor ? ($actor->upn ?? $actor->username ?? null) : null,
            'entity_type' => $entityType ?? 'system',
            'entity_id' => $entityId,
            'object_name' => isset($context['object_name']) ? mb_substr((string) $context['object_name'], 0, 200) : null,
            'process_instance_id' => $processInstanceId,
            'flow_key' => $context['flow_key'] ?? $bpm['flow_key'] ?? null,
            'definition_version' => $context['definition_version'] ?? $bpm['definition_version'] ?? null,
            'engine_process_instance_id' => $bpm['engine_process_instance_id'] ?? null,
            'instance_number' => $bpm['instance_number'] ?? null,
            'stage_key' => $context['stage_key'] ?? $bpm['stage_key'] ?? null,
            'task_id' => $context['task_id'] ?? $bpm['task_id'] ?? null,
            'form_schema_version_id' => $context['form_schema_version_id'] ?? ($entityType === 'form_schema_version' ? $entityId : null),
            'correlation_id' => Correlation::id(),
            'causation_id' => $context['causation_id'] ?? AuditContext::causationId(),
            'idempotency_key' => isset($context['idempotency_key']) ? mb_substr((string) $context['idempotency_key'], 0, 160) : null,
            'outbox_id' => $context['outbox_id'] ?? null,
            'data' => Masker::mask($data),
            'diff' => $diff !== null ? Masker::mask($diff) : null,
            'occurred_at' => now('UTC'),
            ...$this->requestContext(),
        ];

        return AuditEvent::create($row);
    }

    /** @return array<string,mixed> */
    private function requestContext(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return [];
        }
        /** @var Request|null $request */
        $request = app()->bound('request') ? app('request') : null;
        if (! $request || ! $request->route()) {
            return [];
        }
        $route = $request->route();
        $uri = $route->uri();

        return [
            'operation' => mb_substr($request->method().' /'.ltrim((string) $uri, '/'), 0, 160),
            'ip_address' => config('bpm.audit.capture_ip') ? $request->ip() : null,
            'user_agent' => config('bpm.audit.capture_user_agent') ? mb_substr((string) $request->userAgent(), 0, 300) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function bpmContext(?string $entityType, ?string $entityId, ?string $instanceId): array
    {
        $ctx = [];
        if ($entityType === 'task_projection' && $entityId) {
            $task = TaskProjection::find($entityId, ['id', 'stage_key', 'process_instance_id']);
            if ($task) {
                $ctx['task_id'] = $task->id;
                $ctx['stage_key'] = $task->stage_key;
                $instanceId ??= $task->process_instance_id;
            }
        }
        if ($entityType === 'process_definition_version' && $entityId) {
            $version = ProcessDefinitionVersion::with('catalog:id,key')->find($entityId, ['id', 'catalog_id', 'version']);
            if ($version) {
                $ctx['flow_key'] = $version->catalog->key ?? null;
                $ctx['definition_version'] = $version->version;
            }
        }
        if ($instanceId) {
            $ctx += $this->instanceContext($instanceId);
        }

        return $ctx;
    }

    /** @return array<string,mixed> */
    private function instanceContext(string $id): array
    {
        if (isset($this->instanceMemo[$id])) {
            return $this->instanceMemo[$id];
        }
        $instance = ProcessInstance::with(['catalog:id,key', 'definitionVersion:id,version'])->find($id, ['id', 'number', 'catalog_id', 'definition_version_id', 'engine_process_instance_id']);
        if (! $instance) {
            return [];
        }
        $ctx = [
            'instance_number' => $instance->number,
            'flow_key' => $instance->catalog->key ?? null,
            'definition_version' => $instance->definitionVersion->version ?? null,
            'engine_process_instance_id' => $instance->engine_process_instance_id,
        ];
        if ($instance->engine_process_instance_id) {
            $this->instanceMemo[$id] = $ctx; // só memoriza quando o engine já confirmou
        }

        return $ctx;
    }
}
