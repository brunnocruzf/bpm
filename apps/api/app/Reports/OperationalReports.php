<?php

namespace App\Reports;

use App\Models\BpmGroup;
use App\Models\EngineCommand;
use App\Models\IntegrationExecution;
use App\Models\NotificationMessage;
use App\Models\ProcessCatalog;
use App\Models\ProcessInstance;
use App\Models\TaskProjection;
use App\Models\User;
use App\Support\ApiProblem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Relatórios operacionais sobre um catálogo de dados aprovado (sem SQL livre). Filtros de data
 * são dias de negócio no fuso configurado (America/Sao_Paulo), convertidos para UTC com limites
 * [início do dia, fim do dia]. Durações em horas a partir de timestamps UTC.
 */
class OperationalReports
{
    public const TYPES = [
        'instances' => 'Instâncias abertas/concluídas/canceladas',
        'stage_aging' => 'Aging e tempo por etapa',
        'pending' => 'Pendências por grupo e responsável',
        'sla' => 'Prazos (SLA) por etapa',
        'integration_errors' => 'Erros e reprocessamentos de integração',
        'notifications' => 'Notificações por estado',
    ];

    public const FILTERS = ['flowKey', 'version', 'number', 'status', 'area', 'stageKey', 'group', 'assigneeId', 'openedFrom', 'openedTo', 'closedFrom', 'closedTo'];

    private string $tz;

    public function __construct()
    {
        $this->tz = (string) config('bpm.sequences.timezone', 'America/Sao_Paulo');
    }

    /**
     * Valida filtros (somente chaves conhecidas, formatos estritos).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function validateFilters(array $input): array
    {
        $out = [];
        $errors = [];
        foreach (self::FILTERS as $f) {
            $v = $input[$f] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $v = is_scalar($v) ? trim((string) $v) : '';
            $ok = match ($f) {
                'flowKey', 'stageKey' => preg_match('/^[a-z][a-z0-9_]{0,79}$/', $v) === 1,
                'version' => ctype_digit($v),
                'number' => preg_match('/^[A-Z0-9\-\/]{1,40}$/i', $v) === 1,
                'status' => in_array($v, ['open', 'running', 'completed', 'cancelled', 'error', 'starting'], true),
                'group' => preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $v) === 1,
                'assigneeId' => preg_match('/^[0-9a-f\-]{36}$/i', $v) === 1,
                'area' => mb_strlen($v) <= 100,
                default => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false,
            };
            if (! $ok) {
                $errors["filters.$f"][] = 'Filtro inválido.';

                continue;
            }
            $out[$f] = $f === 'version' ? (int) $v : $v;
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $out;
    }

    /** @param array<string,mixed> $filters */
    public function build(string $type, array $filters, int $maxRows): ReportDocument
    {
        $doc = new ReportDocument(self::TYPES[$type] ?? $type, 'relatorio-'.$type.'-'.now($this->tz)->format('Ymd-Hi'));
        $doc->heading(self::TYPES[$type])->paragraph($this->filterSummary($filters), 'note');

        match ($type) {
            'instances' => $this->instances($doc, $filters, $maxRows),
            'stage_aging' => $this->stageAging($doc, $filters),
            'pending' => $this->pending($doc, $filters),
            'sla' => $this->sla($doc, $filters),
            'integration_errors' => $this->integrationErrors($doc, $filters, $maxRows),
            'notifications' => $this->notifications($doc, $filters, $maxRows),
            default => throw ApiProblem::validation(['type' => ['Relatório desconhecido.']]),
        };
        $doc->data = ['type' => $type, 'filters' => $filters, 'rows' => $doc->table['rows'] ?? []];

        return $doc;
    }

    /** @return array{0:?Carbon,1:?Carbon} */
    public function dayRange(?string $from, ?string $to): array
    {
        return [
            $from ? Carbon::parse($from, $this->tz)->startOfDay()->utc() : null,
            $to ? Carbon::parse($to, $this->tz)->endOfDay()->utc() : null,
        ];
    }

    /** @return Builder<ProcessInstance> */
    private function instanceQuery(array $f): Builder
    {
        $q = ProcessInstance::query()->with(['catalog:id,key,name', 'definitionVersion:id,version']);
        if (isset($f['flowKey'])) {
            $q->whereIn('catalog_id', ProcessCatalog::where('key', $f['flowKey'])->select('id'));
        }
        if (isset($f['version'])) {
            $q->whereHas('definitionVersion', fn ($v) => $v->where('version', $f['version']));
        }
        if (isset($f['number'])) {
            $q->where('number', $f['number']);
        }
        if (isset($f['status'])) {
            $f['status'] === 'open' ? $q->whereIn('status', [ProcessInstance::STARTING, ProcessInstance::RUNNING]) : $q->where('status', $f['status']);
        }
        if (isset($f['area'])) {
            $q->where('requester_area', 'like', '%'.addcslashes($f['area'], '%_').'%');
        }
        [$of, $ot] = $this->dayRange($f['openedFrom'] ?? null, $f['openedTo'] ?? null);
        $of && $q->where('started_at', '>=', $of);
        $ot && $q->where('started_at', '<=', $ot);
        [$cf, $ct] = $this->dayRange($f['closedFrom'] ?? null, $f['closedTo'] ?? null);
        $cf && $q->where('ended_at', '>=', $cf);
        $ct && $q->where('ended_at', '<=', $ct);

        return $q;
    }

    private function instances(ReportDocument $doc, array $f, int $max): void
    {
        $items = $this->instanceQuery($f)->orderBy('started_at')->limit($max + 1)->get();
        $truncated = $items->count() > $max;
        $rows = $items->take($max)->map(fn (ProcessInstance $i) => [
            'number' => $i->number, 'flow' => $i->catalog->name, 'version' => $i->definitionVersion->version ?? null,
            'status' => $i->status, 'result' => $i->result ?? '', 'requester' => $i->requester_name, 'area' => $i->requester_area ?? '',
            'openedAt' => $this->local($i->started_at), 'closedAt' => $this->local($i->ended_at),
            'durationHours' => $i->ended_at ? round($i->started_at->diffInMinutes($i->ended_at) / 60, 1) : null,
        ])->values()->all();
        $counts = ['open' => 0, 'completed' => 0, 'cancelled' => 0, 'error' => 0];
        foreach ($items->take($max) as $i) {
            $k = in_array($i->status, ['starting', 'running'], true) ? 'open' : $i->status;
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }
        $doc->keyValues([['Abertas', (string) $counts['open']], ['Concluídas', (string) $counts['completed']], ['Canceladas', (string) $counts['cancelled']], ['Com erro', (string) $counts['error']], ['Total', (string) count($rows)]]);
        $columns = [['key' => 'number', 'label' => 'Protocolo'], ['key' => 'flow', 'label' => 'Fluxo'], ['key' => 'version', 'label' => 'Versão'], ['key' => 'status', 'label' => 'Status'], ['key' => 'result', 'label' => 'Resultado'], ['key' => 'requester', 'label' => 'Solicitante'], ['key' => 'area', 'label' => 'Área'], ['key' => 'openedAt', 'label' => 'Aberta em'], ['key' => 'closedAt', 'label' => 'Encerrada em'], ['key' => 'durationHours', 'label' => 'Duração (h)']];
        $this->finish($doc, $columns, $rows, $truncated, [0.12, 0.16, 0.06, 0.09, 0.1, 0.13, 0.1, 0.09, 0.09, 0.06]);
    }

    /** @return Builder<TaskProjection> */
    private function taskQuery(array $f): Builder
    {
        $q = TaskProjection::query()->whereIn('process_instance_id', $this->instanceQuery(array_diff_key($f, array_flip(['stageKey', 'group', 'assigneeId'])))->select('id'));
        isset($f['stageKey']) && $q->where('stage_key', $f['stageKey']);
        isset($f['group']) && $q->where('candidate_group', $f['group']);
        isset($f['assigneeId']) && $q->where('assignee_id', $f['assigneeId']);

        return $q;
    }

    private function stageAging(ReportDocument $doc, array $f): void
    {
        $now = now();
        $rows = [];
        foreach ($this->taskQuery($f)->with('instance.catalog:id,name')->get()->groupBy(fn ($t) => ($t->instance->catalog->name ?? '?').'|'.$t->stage_key) as $group) {
            /** @var TaskProjection $first */
            $first = $group->first();
            $done = $group->filter(fn ($t) => $t->status === TaskProjection::COMPLETED && $t->completed_at)
                ->map(fn ($t) => ($t->engine_created_at ?? $t->created_at)->diffInMinutes($t->completed_at) / 60)->sort()->values();
            $open = $group->filter(fn ($t) => $t->isActive());
            $rows[] = [
                'flow' => $first->instance->catalog->name ?? '?', 'stage' => $first->name, 'group' => $this->groupName($first->candidate_group),
                'completed' => $done->count(), 'avgHours' => $done->isEmpty() ? null : round($done->avg(), 1),
                'p50Hours' => self::percentile($done->all(), 0.5), 'p90Hours' => self::percentile($done->all(), 0.9),
                'open' => $open->count(),
                'oldestOpenHours' => $open->isEmpty() ? null : round($open->max(fn ($t) => ($t->engine_created_at ?? $t->created_at)->diffInMinutes($now) / 60), 1),
            ];
        }
        $columns = [['key' => 'flow', 'label' => 'Fluxo'], ['key' => 'stage', 'label' => 'Etapa'], ['key' => 'group', 'label' => 'Grupo'], ['key' => 'completed', 'label' => 'Concluídas'], ['key' => 'avgHours', 'label' => 'Média (h)'], ['key' => 'p50Hours', 'label' => 'Mediana (h)'], ['key' => 'p90Hours', 'label' => 'P90 (h)'], ['key' => 'open', 'label' => 'Abertas'], ['key' => 'oldestOpenHours', 'label' => 'Mais antiga aberta (h)']];
        $this->finish($doc, $columns, $rows, false);
    }

    private function pending(ReportDocument $doc, array $f): void
    {
        $now = now();
        $rows = [];
        $tasks = $this->taskQuery($f)->whereIn('status', [TaskProjection::OPEN, TaskProjection::CLAIMED])->get();
        $names = User::whereIn('id', $tasks->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        foreach ($tasks->groupBy(fn ($t) => ($t->candidate_group ?? '—').'|'.($t->assignee_id ?? '')) as $group) {
            $first = $group->first();
            $rows[] = [
                'group' => $this->groupName($first->candidate_group),
                'assignee' => $first->assignee_id ? ($names[$first->assignee_id] ?? '—') : 'Não assumidas (grupo)',
                'open' => $group->count(),
                'overdue' => $group->filter(fn ($t) => $t->due_at && $t->due_at->lt($now))->count(),
                'oldestHours' => round($group->max(fn ($t) => ($t->engine_created_at ?? $t->created_at)->diffInMinutes($now) / 60), 1),
            ];
        }
        usort($rows, fn ($a, $b) => $b['open'] <=> $a['open']);
        $columns = [['key' => 'group', 'label' => 'Grupo'], ['key' => 'assignee', 'label' => 'Responsável'], ['key' => 'open', 'label' => 'Pendentes'], ['key' => 'overdue', 'label' => 'Atrasadas'], ['key' => 'oldestHours', 'label' => 'Mais antiga (h)']];
        $doc->paragraph('Responsável exibido conforme o cadastro atual (dado operacional, não histórico).', 'small');
        $this->finish($doc, $columns, $rows, false);
    }

    private function sla(ReportDocument $doc, array $f): void
    {
        $now = now();
        $rows = [];
        foreach ($this->taskQuery($f)->whereNotNull('due_at')->with('instance.catalog:id,name')->get()->groupBy(fn ($t) => ($t->instance->catalog->name ?? '?').'|'.$t->stage_key) as $group) {
            $first = $group->first();
            $completed = $group->filter(fn ($t) => $t->status === TaskProjection::COMPLETED && $t->completed_at);
            $onTime = $completed->filter(fn ($t) => $t->completed_at->lte($t->due_at))->count();
            $late = $completed->count() - $onTime;
            $overdueOpen = $group->filter(fn ($t) => $t->isActive() && $t->due_at->lt($now))->count();
            $rows[] = [
                'flow' => $first->instance->catalog->name ?? '?', 'stage' => $first->name, 'total' => $group->count(),
                'onTime' => $onTime, 'late' => $late, 'overdueOpen' => $overdueOpen,
                'onTimePct' => $completed->isEmpty() ? null : round($onTime * 100 / $completed->count(), 1),
            ];
        }
        $columns = [['key' => 'flow', 'label' => 'Fluxo'], ['key' => 'stage', 'label' => 'Etapa'], ['key' => 'total', 'label' => 'Com prazo'], ['key' => 'onTime', 'label' => 'No prazo'], ['key' => 'late', 'label' => 'Concluídas com atraso'], ['key' => 'overdueOpen', 'label' => 'Abertas vencidas'], ['key' => 'onTimePct', 'label' => '% no prazo']];
        $doc->paragraph('Somente etapas com prazo (SLA) definido. Prazos dos fluxos são provisórios até validação das áreas.', 'small');
        $this->finish($doc, $columns, $rows, false);
    }

    private function integrationErrors(ReportDocument $doc, array $f, int $max): void
    {
        [$from, $to] = $this->dayRange($f['openedFrom'] ?? null, $f['openedTo'] ?? null);
        $rows = [];
        $commands = EngineCommand::where(fn ($q) => $q->whereIn('state', [EngineCommand::FAILED, EngineCommand::RECONCILIATION_REQUIRED])->orWhere('attempts', '>', 1));
        $from && $commands->where('created_at', '>=', $from);
        $to && $commands->where('created_at', '<=', $to);
        $numbers = [];
        foreach ($commands->orderBy('created_at')->limit($max)->get() as $c) {
            $numbers[$c->process_instance_id ?? ''] ??= $c->process_instance_id ? ProcessInstance::whereKey($c->process_instance_id)->value('number') : null;
            $rows[] = ['at' => $this->local($c->created_at), 'system' => 'Flowable', 'operation' => $c->command, 'state' => $c->state, 'attempts' => $c->attempts, 'number' => $numbers[$c->process_instance_id ?? ''] ?? '', 'detail' => mb_substr((string) $c->last_error, 0, 200)];
        }
        $execs = IntegrationExecution::where('result', '!=', 'success');
        $from && $execs->where('created_at', '>=', $from);
        $to && $execs->where('created_at', '<=', $to);
        foreach ($execs->orderBy('created_at')->limit($max)->get() as $e) {
            $numbers[$e->process_instance_id ?? ''] ??= $e->process_instance_id ? ProcessInstance::whereKey($e->process_instance_id)->value('number') : null;
            $rows[] = ['at' => $this->local($e->created_at), 'system' => strtoupper((string) $e->system).' ('.$e->transport.')', 'operation' => $e->operation, 'state' => $e->result, 'attempts' => $e->attempt, 'number' => $numbers[$e->process_instance_id ?? ''] ?? '', 'detail' => ''];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        $columns = [['key' => 'at', 'label' => 'Data/hora'], ['key' => 'system', 'label' => 'Sistema'], ['key' => 'operation', 'label' => 'Operação'], ['key' => 'state', 'label' => 'Estado'], ['key' => 'attempts', 'label' => 'Tentativas'], ['key' => 'number', 'label' => 'Protocolo'], ['key' => 'detail', 'label' => 'Erro (sanitizado)']];
        $this->finish($doc, $columns, array_slice($rows, 0, $max), count($rows) > $max);
    }

    private function notifications(ReportDocument $doc, array $f, int $max): void
    {
        [$from, $to] = $this->dayRange($f['openedFrom'] ?? null, $f['openedTo'] ?? null);
        $q = NotificationMessage::query();
        $from && $q->where('created_at', '>=', $from);
        $to && $q->where('created_at', '<=', $to);
        $summary = (clone $q)->selectRaw('event, state, count(*) as total')->groupBy('event', 'state')->get();
        $doc->table([['label' => 'Tipo'], ['label' => 'Estado'], ['label' => 'Total']], $summary->map(fn (NotificationMessage $r) => [$r->event, $r->state, (string) $r->getAttribute('total')])->all() ?: [['—', '—', '0']]);
        $rows = (clone $q)->whereIn('state', [NotificationMessage::FAILED, NotificationMessage::RETRYING, NotificationMessage::SKIPPED])->orderBy('created_at')->limit($max)->get()->map(fn (NotificationMessage $m) => [
            'at' => $this->local($m->created_at), 'event' => $m->event, 'state' => $m->state, 'recipient' => $m->recipient, 'attempts' => $m->attempts, 'code' => $m->error_code ?? '', 'provider' => $m->provider ?? '',
        ])->all();
        $columns = [['key' => 'at', 'label' => 'Criada em'], ['key' => 'event', 'label' => 'Tipo'], ['key' => 'state', 'label' => 'Estado'], ['key' => 'recipient', 'label' => 'Destino lógico'], ['key' => 'attempts', 'label' => 'Tentativas'], ['key' => 'code', 'label' => 'Código de erro'], ['key' => 'provider', 'label' => 'Provedor']];
        $doc->paragraph('Detalhe: falhas, reagendamentos e descartes (sem endereço de e-mail).', 'small');
        $this->finish($doc, $columns, $rows, count($rows) >= $max);
    }

    /**
     * @param  list<array{key:string,label:string}>  $columns
     * @param  list<array<string,mixed>>  $rows
     * @param  list<float>|null  $widths
     */
    private function finish(ReportDocument $doc, array $columns, array $rows, bool $truncated, ?array $widths = null): void
    {
        $doc->setTable($columns, $rows);
        $doc->table(
            array_map(fn ($c, $i) => ['label' => $c['label']] + ($widths ? ['width' => $widths[$i]] : []), $columns, array_keys($columns)),
            $rows ? array_map(fn ($r) => array_map(fn ($c) => self::cell($r[$c['key']] ?? null), $columns), $rows) : [array_map(fn () => '—', $columns)],
        );
        if ($truncated) {
            $doc->paragraph('Resultado truncado no limite de linhas configurado; refine os filtros.', 'note');
        }
    }

    private static function cell(mixed $v): string
    {
        return match (true) {
            $v === null || $v === '' => '—',
            is_float($v) => number_format($v, 1, ',', '.'),
            is_bool($v) => $v ? 'Sim' : 'Não',
            default => (string) $v,
        };
    }

    /** @param list<float> $sorted */
    public static function percentile(array $sorted, float $p): ?float
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }
        sort($sorted);
        $idx = ($n - 1) * $p;
        $lo = (int) floor($idx);
        $hi = (int) ceil($idx);

        return round($sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($idx - $lo), 1);
    }

    private function local(?\DateTimeInterface $d): string
    {
        return $d ? Carbon::instance($d)->setTimezone($this->tz)->format('d/m/Y H:i') : '';
    }

    private function groupName(?string $key): string
    {
        return $key ? (BpmGroup::where('key', $key)->value('name') ?? $key) : 'Responsável direto';
    }

    private function filterSummary(array $f): string
    {
        if (! $f) {
            return 'Sem filtros · horários em '.$this->tz.'.';
        }
        $labels = ['flowKey' => 'Fluxo', 'version' => 'Versão', 'number' => 'Protocolo', 'status' => 'Status', 'area' => 'Área', 'stageKey' => 'Etapa', 'group' => 'Grupo', 'assigneeId' => 'Responsável', 'openedFrom' => 'Aberta de', 'openedTo' => 'Aberta até', 'closedFrom' => 'Encerrada de', 'closedTo' => 'Encerrada até'];

        return 'Filtros: '.implode(' · ', array_map(fn ($k, $v) => ($labels[$k] ?? $k).' = '.$v, array_keys($f), $f)).' · horários em '.$this->tz.'.';
    }
}
