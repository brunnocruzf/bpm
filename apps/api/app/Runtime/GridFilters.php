<?php

namespace App\Runtime;

use App\Models\BpmGroup;
use App\Models\ProcessInstance;
use App\Models\TaskProjection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Filtros de coluna dos grids operacionais (Acompanhar e Tarefas). Texto é parcial e sem diferenciar
 * maiúsculas; datas são dias no fuso da operação; idade é uma faixa "min-max" em dias ("31-" sem teto),
 * com a mesma contagem exibida no grid (dias completos).
 */
final class GridFilters
{
    public const AGE_PATTERN = '/^(\d{1,4})-(\d{0,4})$/';

    public const DUE = ['overdue', 'ontime', 'today', 'week', 'none'];

    /** Etapas exibidas como "atuais" no grid de solicitações (as em processamento ficam de fora). */
    private const CURRENT = [TaskProjection::OPEN, TaskProjection::CLAIMED];

    /** GET /process-instances: q, number, requester, area, startedFrom/To, stage, assignee, age, due. */
    public static function instances(Builder $query, Request $request): void
    {
        self::validate($request, ['startedFrom', 'startedTo']);

        if ($like = self::like($request, 'q')) {
            self::whereContains($query, ['number', 'requester_name', 'requester_area'], $like);
        }
        if ($like = self::like($request, 'number')) {
            self::whereNumberOrFlow($query, $like);
        }
        if ($like = self::like($request, 'requester')) {
            self::whereContains($query, ['requester_name'], $like);
        }
        if ($like = self::like($request, 'area')) {
            self::whereContains($query, ['requester_area'], $like);
        }
        self::whereDays($query, 'started_at', $request->query('startedFrom'), $request->query('startedTo'));
        if ($like = self::like($request, 'stage')) {
            $query->whereHas('tasks', fn (Builder $t) => $t->whereIn('status', self::CURRENT)->whereRaw('lower(name) like ?', [$like]));
        }
        if ($like = self::like($request, 'assignee')) {
            $query->whereHas('tasks', fn (Builder $t) => self::whereResponsible($t->whereIn('status', self::CURRENT), $like));
        }
        if ($age = $request->query('age')) {
            self::whereAgeDays($query, (string) $age, 'started_at', 'ended_at');
        }
        if ($due = $request->query('due')) {
            $due === 'none'
                ? $query->whereDoesntHave('tasks', fn (Builder $t) => $t->whereIn('status', self::CURRENT)->whereNotNull('due_at'))
                : $query->whereHas('tasks', fn (Builder $t) => self::whereDue($t->whereIn('status', self::CURRENT), (string) $due));
        }
    }

    /** GET /tasks: q, number, stage, assignee, requester, status, age, completedFrom/To, due. */
    public static function tasks(Builder $query, Request $request): void
    {
        self::validate($request, ['completedFrom', 'completedTo']);

        if ($like = self::like($request, 'q')) {
            $query->where(fn (Builder $w) => $w->whereRaw('lower(name) like ?', [$like])
                ->orWhereHas('instance', fn (Builder $i) => self::whereContains($i, ['number', 'requester_name', 'requester_area'], $like)));
        }
        if ($like = self::like($request, 'number')) {
            $query->whereHas('instance', fn (Builder $i) => self::whereNumberOrFlow($i, $like));
        }
        if ($like = self::like($request, 'stage')) {
            self::whereContains($query, ['name'], $like);
        }
        if ($like = self::like($request, 'assignee')) {
            self::whereResponsible($query, $like);
        }
        if ($like = self::like($request, 'requester')) {
            $query->whereHas('instance', fn (Builder $i) => self::whereContains($i, ['requester_name', 'requester_area'], $like));
        }
        if ($status = $request->query('status')) {
            $query->whereIn('status', explode(',', (string) $status));
        }
        if ($age = $request->query('age')) {
            self::whereAgeDays($query, (string) $age, 'coalesce(engine_created_at, created_at)', 'completed_at');
        }
        self::whereDays($query, 'completed_at', $request->query('completedFrom'), $request->query('completedTo'));
        if ($due = $request->query('due')) {
            self::whereDue($query, (string) $due);
        }
    }

    /** Ordenação por coluna (?sort=chave&dir=asc|desc) do grid de solicitações; padrão: mais recentes primeiro. */
    public static function sortInstances(Builder $query, Request $request): void
    {
        $current = fn (string $column, string $agg) => TaskProjection::selectRaw("$agg($column)")
            ->whereColumn('process_instance_id', 'process_instance.id')->whereIn('status', self::CURRENT);
        self::sort($query, $request, [
            'number' => 'number',
            'requester' => 'requester_name',
            'area' => 'requester_area',
            'started' => 'started_at',
            'stage' => $current('name', 'min'),
            'assignee' => $current('candidate_group', 'min'),
            'age' => 'started_at',
            'due' => $current('due_at', 'min'),
            'status' => 'status',
        ], ['started_at', 'desc'], invert: ['age']);
    }

    /** Ordenação por coluna do grid de tarefas; sem ?sort mantém o padrão da aba. */
    public static function sortTasks(Builder $query, Request $request, array $default): void
    {
        $instance = fn (string $column) => ProcessInstance::select($column)->whereColumn('id', 'task_projection.process_instance_id');
        self::sort($query, $request, [
            'number' => $instance('number'),
            'stage' => 'name',
            'assignee' => 'candidate_group',
            'requester' => $instance('requester_name'),
            'age' => 'created_at',
            'completed' => 'completed_at',
            'due' => 'due_at',
            'status' => 'status',
        ], $default, invert: ['age']);
    }

    /**
     * @param  array<string, string|Builder>  $columns
     * @param  array{0: string, 1: string}  $default
     * @param  list<string>  $invert  chaves cuja ordem "crescente" corresponde à data decrescente (idade)
     */
    private static function sort(Builder $query, Request $request, array $columns, array $default, array $invert = []): void
    {
        $request->validate(['sort' => ['nullable', Rule::in(array_keys($columns))], 'dir' => ['nullable', Rule::in(['asc', 'desc'])]]);
        $key = $request->query('sort');
        if (! $key) {
            $query->orderBy(...$default);

            return;
        }
        $dir = $request->query('dir', 'asc');
        if (in_array($key, $invert, true)) {
            $dir = $dir === 'asc' ? 'desc' : 'asc';
        }
        $query->orderBy($columns[$key], $dir)->orderBy($default[0], $default[1]);
    }

    /** @param  list<string>  $dates */
    private static function validate(Request $request, array $dates): void
    {
        $request->validate([
            ...array_fill_keys($dates, ['nullable', 'date_format:Y-m-d']),
            'age' => ['nullable', 'regex:'.self::AGE_PATTERN],
            'due' => ['nullable', Rule::in(self::DUE)],
        ]);
    }

    /** Padrão LIKE ("%texto%", minúsculo e escapado) do parâmetro, ou null quando vazio. */
    private static function like(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        return $value === '' ? null : '%'.addcslashes(mb_strtolower($value), '%_').'%';
    }

    /** @param  list<string>  $columns  ao menos uma contém o texto */
    private static function whereContains(Builder $query, array $columns, string $like): Builder
    {
        return $query->where(function (Builder $w) use ($columns, $like) {
            foreach ($columns as $column) {
                $w->orWhereRaw("lower($column) like ?", [$like]);
            }
        });
    }

    private static function whereNumberOrFlow(Builder $instances, string $like): Builder
    {
        return $instances->where(fn (Builder $w) => $w->whereRaw('lower(number) like ?', [$like])
            ->orWhereHas('catalog', fn (Builder $c) => $c->whereRaw('lower(name) like ?', [$like])));
    }

    /** Responsável exibido: grupo candidato (nome ou chave) ou pessoa que assumiu a tarefa. */
    private static function whereResponsible(Builder $tasks, string $like): Builder
    {
        return $tasks->where(fn (Builder $w) => $w->whereRaw('lower(candidate_group) like ?', [$like])
            ->orWhereIn('candidate_group', BpmGroup::select('key')->whereRaw('lower(name) like ?', [$like]))
            ->orWhereHas('assignee', fn (Builder $u) => $u->whereRaw('lower(name) like ?', [$like])));
    }

    private static function whereDays(Builder $query, string $column, mixed $from, mixed $to): void
    {
        $from && $query->where($column, '>=', Carbon::parse((string) $from, self::tz())->startOfDay()->utc());
        $to && $query->where($column, '<=', Carbon::parse((string) $to, self::tz())->endOfDay()->utc());
    }

    /** Idade = dias completos entre o início e o fim (ou agora, se ainda aberto). */
    private static function whereAgeDays(Builder $query, string $range, string $start, string $end): void
    {
        preg_match(self::AGE_PATTERN, $range, $m);
        $min = (int) $m[1];
        $max = $m[2] === '' ? null : (int) $m[2];
        $elapsed = self::secondsBetween($query, $start, $end);

        $query->where(fn (Builder $w) => $w
            ->where(fn (Builder $open) => $open->whereNull($end)->whereRaw("$start <= ?", [now()->subDays($min)])
                ->when($max !== null, fn (Builder $o) => $o->whereRaw("$start > ?", [now()->subDays($max + 1)])))
            ->orWhere(fn (Builder $closed) => $closed->whereNotNull($end)->whereRaw("$elapsed >= ?", [$min * 86400])
                ->when($max !== null, fn (Builder $c) => $c->whereRaw("$elapsed < ?", [($max + 1) * 86400]))));
    }

    /** Situação do prazo; "vencido" inclui tarefa concluída depois do prazo. */
    private static function whereDue(Builder $tasks, string $due): Builder
    {
        $now = now();

        return match ($due) {
            'overdue' => $tasks->whereNotNull('due_at')->where(fn (Builder $w) => $w->where(fn (Builder $a) => $a->whereNull('completed_at')->where('due_at', '<', $now))->orWhereColumn('completed_at', '>', 'due_at')),
            'ontime' => $tasks->whereNotNull('due_at')->where(fn (Builder $w) => $w->where(fn (Builder $a) => $a->whereNull('completed_at')->where('due_at', '>=', $now))->orWhereColumn('completed_at', '<=', 'due_at')),
            'today' => $tasks->whereBetween('due_at', [$now, Carbon::now(self::tz())->endOfDay()->utc()]),
            'week' => $tasks->whereBetween('due_at', [$now, $now->copy()->addDays(7)]),
            default => $tasks->whereNull('due_at'),
        };
    }

    private static function tz(): string
    {
        return (string) config('bpm.sequences.timezone', 'America/Sao_Paulo');
    }

    private static function secondsBetween(Builder $query, string $from, string $to): string
    {
        return match ($query->getModel()->getConnection()->getDriverName()) {
            'sqlite' => "(julianday($to) - julianday($from)) * 86400",
            'sqlsrv' => "datediff(second, $from, $to)",
            'mysql', 'mariadb' => "timestampdiff(second, $from, $to)",
            default => "extract(epoch from ($to - $from))",
        };
    }
}
