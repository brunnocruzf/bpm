<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditCatalog;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AuditEvent;
use App\Reports\TabularWriter;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trilha de auditoria funcional: consulta (audit.read) e exportação (audit.export, auditada).
 * Não existem rotas de alteração/exclusão (append-only; trigger no banco reforça).
 */
class AuditController extends ApiController
{
    private const FILTERS = ['from', 'to', 'actor', 'action', 'category', 'entityType', 'entityId', 'flowKey', 'number', 'stageKey', 'outcome', 'correlationId', 'processInstanceId'];

    public function catalog(): JsonResponse
    {
        Gate::authorize('viewAny', AuditEvent::class);

        return response()->json(['categories' => collect(AuditCatalog::CATEGORIES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(), 'events' => AuditCatalog::describe()]);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AuditEvent::class);
        $filters = $this->filters($request);
        $perPage = min(100, max(10, (int) $request->query('perPage', 50)));
        $page = $this->query($filters, (string) $request->query('sort', 'desc'))->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditEvent $e) => $this->present($e, false)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'perPage' => $page->perPage(), 'lastPage' => $page->lastPage()],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $event = AuditEvent::find($id) ?? throw ApiProblem::notFound();
        Gate::authorize('view', $event);
        $related = AuditEvent::query()
            ->where(function (Builder $q) use ($event) {
                $q->where('correlation_id', $event->correlation_id);
                if ($event->causation_id) {
                    $q->orWhere('id', $event->causation_id)->orWhere('causation_id', $event->causation_id);
                }
                $q->orWhere('causation_id', $event->id);
                if ($event->outbox_id) {
                    $q->orWhere('outbox_id', $event->outbox_id);
                }
                if ($event->entity_id) {
                    // mesmo objeto (ex.: solicitação, geração e downloads de um relatório)
                    $q->orWhere(fn (Builder $o) => $o->where('entity_type', $event->entity_type)->where('entity_id', $event->entity_id));
                }
            })
            ->where('id', '!=', $event->id)
            ->orderBy('occurred_at')->limit(200)->get();

        return response()->json([...$this->present($event, true), 'related' => $related->map(fn (AuditEvent $e) => $this->present($e, false))]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('export', AuditEvent::class);
        $format = (string) $request->query('format', 'csv');
        if (! in_array($format, ['csv', 'json'], true)) {
            throw ApiProblem::validation(['format' => ['Use csv ou json.']]);
        }
        $filters = $this->filters($request);
        $max = (int) config('bpm.audit.export_max_rows', 50000);
        $query = $this->query($filters, 'asc');
        $count = min($max, (clone $query)->count());
        // a própria exportação é auditada antes do envio dos dados
        AuditLogger::record('audit.exported', 'audit_event', null, ['format' => $format, 'filters' => $filters, 'rows' => $count], null, $this->user());

        $name = 'auditoria-'.now((string) config('bpm.sequences.timezone'))->format('Ymd-His').'.'.$format;
        $columns = ['occurredAt', 'category', 'action', 'outcome', 'actorType', 'actorName', 'actorUpn', 'entityType', 'entityId', 'objectName', 'flowKey', 'definitionVersion', 'instanceNumber', 'engineProcessInstanceId', 'stageKey', 'taskId', 'correlationId', 'causationId', 'outboxId', 'operation', 'ipAddress'];

        return response()->streamDownload(function () use ($query, $max, $format, $columns) {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            if ($format === 'csv') {
                fwrite($out, "\xEF\xBB\xBF".implode(';', array_merge($columns, ['data', 'diff']))."\r\n");
            } else {
                fwrite($out, '[');
            }
            $first = true;
            $written = 0;
            foreach ($query->lazy(500) as $e) {
                if ($written++ >= $max) {
                    break;
                }
                $row = $this->present($e, true);
                if ($format === 'json') {
                    fwrite($out, ($first ? '' : ',')."\n".json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                } else {
                    $cells = array_map(fn ($c) => $row[$c] ?? '', $columns);
                    $cells[] = json_encode($row['data'] ?? [], JSON_UNESCAPED_UNICODE);
                    $cells[] = json_encode($row['diff'] ?? null, JSON_UNESCAPED_UNICODE);
                    fwrite($out, implode(';', array_map(fn ($v) => '"'.str_replace('"', '""', TabularWriter::safeText($v)).'"', $cells))."\r\n");
                }
                $first = false;
            }
            if ($format === 'json') {
                fwrite($out, "\n]\n");
            }
            fclose($out);
        }, $name, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string,string> */
    private function filters(Request $request): array
    {
        $out = [];
        foreach (self::FILTERS as $f) {
            $v = $request->query($f);
            if (is_string($v) && trim($v) !== '') {
                $out[$f] = mb_substr(trim($v), 0, 160);
            }
        }
        foreach (['from', 'to'] as $d) {
            if (isset($out[$d]) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $out[$d])) {
                throw ApiProblem::validation([$d => ['Use o formato AAAA-MM-DD.']]);
            }
        }
        if (isset($out['outcome']) && ! in_array($out['outcome'], ['success', 'failure', 'blocked'], true)) {
            throw ApiProblem::validation(['outcome' => ['Resultado inválido.']]);
        }

        return $out;
    }

    /**
     * @param  array<string,string>  $f
     * @return Builder<AuditEvent>
     */
    private function query(array $f, string $sort): Builder
    {
        $tz = (string) config('bpm.sequences.timezone');
        $q = AuditEvent::query();
        isset($f['from']) && $q->where('occurred_at', '>=', Carbon::parse($f['from'], $tz)->startOfDay()->utc());
        isset($f['to']) && $q->where('occurred_at', '<=', Carbon::parse($f['to'], $tz)->endOfDay()->utc());
        if (isset($f['actor'])) {
            $actor = $f['actor'];
            $q->where(fn ($w) => preg_match('/^[0-9a-f\-]{36}$/i', $actor)
                ? $w->where('actor_id', $actor)->orWhere('actor_oid', $actor)
                : $w->where('actor_name', 'like', '%'.addcslashes($actor, '%_').'%')->orWhere('actor_upn', 'like', '%'.addcslashes($actor, '%_').'%'));
        }
        $exact = ['action' => 'action', 'category' => 'category', 'entityType' => 'entity_type', 'entityId' => 'entity_id', 'flowKey' => 'flow_key', 'number' => 'instance_number', 'stageKey' => 'stage_key', 'outcome' => 'outcome', 'correlationId' => 'correlation_id', 'processInstanceId' => 'process_instance_id'];
        foreach ($exact as $in => $col) {
            if (isset($f[$in])) {
                $q->where($col, $f[$in]);
            }
        }

        return $q->orderBy('occurred_at', $sort === 'asc' ? 'asc' : 'desc')->orderBy('id');
    }

    /** @return array<string,mixed> */
    private function present(AuditEvent $e, bool $detail): array
    {
        return [
            'id' => $e->id,
            'occurredAt' => $e->occurred_at->toIso8601String(),
            'category' => $e->category ?? AuditCatalog::category($e->action),
            'action' => $e->action,
            'label' => AuditCatalog::label($e->action),
            'outcome' => $e->outcome ?? 'success',
            'actorType' => $e->actor_type ?? ($e->actor_id ? 'user' : 'system'),
            'actorName' => $e->actor_name,
            'actorUpn' => $e->actor_upn,
            'entityType' => $e->entity_type,
            'entityId' => $e->entity_id,
            'objectName' => $e->object_name,
            'flowKey' => $e->flow_key,
            'definitionVersion' => $e->definition_version,
            'instanceNumber' => $e->instance_number,
            'processInstanceId' => $e->process_instance_id,
            'engineProcessInstanceId' => $e->engine_process_instance_id,
            'stageKey' => $e->stage_key,
            'taskId' => $e->task_id,
            'correlationId' => $e->correlation_id,
            'causationId' => $e->causation_id,
            ...($detail ? [
                'actorOid' => $e->actor_oid,
                'formSchemaVersionId' => $e->form_schema_version_id,
                'idempotencyKey' => $e->idempotency_key,
                'outboxId' => $e->outbox_id,
                'operation' => $e->operation,
                'ipAddress' => $e->ip_address,
                'data' => $e->data,
                'diff' => $e->diff,
            ] : []),
        ];
    }
}
