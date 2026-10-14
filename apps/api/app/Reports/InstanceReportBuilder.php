<?php

namespace App\Reports;

use App\Audit\AuditCatalog;
use App\Authorization\Permissions;
use App\Forms\ConditionalEvaluator;
use App\Forms\FormioSchema;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\BpmGroup;
use App\Models\FormSubmission;
use App\Models\ProcessInstance;
use App\Models\ProcessStageVersion;
use App\Models\TaskProjection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Relatório de uma instância a partir dos SNAPSHOTS que formam o histórico: submissões (payload
 * + schema da versão usada na época), snapshots de solicitante/autor e trilha de auditoria (ator
 * capturado no momento). Não mistura dados atuais do diretório com o histórico (responsável atual
 * só aparece para tarefas abertas, rotulado como tal). Campos sensíveis são mascarados para quem
 * não tem permissão; campos ocultos por condicional aparecem como "não aplicável".
 */
class InstanceReportBuilder
{
    private const STATUS = ['starting' => 'Iniciando', 'running' => 'Em andamento', 'completed' => 'Concluída', 'cancelled' => 'Cancelada', 'error' => 'Erro'];

    private const TASK_STATUS = ['open' => 'Aberta', 'claimed' => 'Assumida', 'completing' => 'Concluindo', 'completed' => 'Concluída', 'cancelled' => 'Cancelada'];

    private string $tz;

    public function __construct()
    {
        $this->tz = (string) config('bpm.sequences.timezone', 'America/Sao_Paulo');
    }

    /**
     * @param  array{mode:string, stages?:list<string>}  $params  mode: summary | stages | full
     */
    public function build(ProcessInstance $instance, User $viewer, array $params): ReportDocument
    {
        $instance->loadMissing(['catalog', 'definitionVersion']);
        $mode = $params['mode'];
        $stages = ProcessStageVersion::where('definition_version_id', $instance->definition_version_id)->orderBy('position')->get();
        // completo = etapas humanas percorridas (com submissão ou tarefa); as demais aparecem só na lista compacta
        $touched = FormSubmission::where('process_instance_id', $instance->id)->pluck('stage_version_id')
            ->merge(TaskProjection::where('process_instance_id', $instance->id)->pluck('stage_version_id'))->unique()->all();
        $humanStages = $stages->where('stage_type', ProcessStageVersion::USER_TASK);
        $selected = match ($mode) {
            'full' => $humanStages->whereIn('id', $touched)->pluck('stage_key')->all(),
            'stages' => (array) ($params['stages'] ?? []),
            default => [],
        };
        $notReached = $mode === 'full' ? $humanStages->whereNotIn('id', $touched)->pluck('name')->all() : [];
        $canSensitive = Permissions::allows($viewer, Permissions::DATA_SENSITIVE);

        $label = match ($mode) {
            'summary' => 'Resumo e linha do tempo',
            'stages' => count($selected) === 1 ? 'Etapa '.($stages->firstWhere('stage_key', $selected[0])->name ?? $selected[0]) : count($selected).' etapas selecionadas',
            default => 'Relatório completo',
        };
        $doc = new ReportDocument("{$instance->number} — {$instance->catalog->name}", 'relatorio-'.preg_replace('/[^A-Za-z0-9]+/', '-', $instance->number));
        $doc->heading("Solicitação {$instance->number}")->paragraph($label.' · '.$instance->catalog->name, 'note');

        $openTasks = TaskProjection::where('process_instance_id', $instance->id)->whereIn('status', TaskProjection::ACTIVE)->get();
        $summary = [
            ['Protocolo', $instance->number],
            ['Fluxo', $instance->catalog->name.' ('.$instance->catalog->key.')'],
            ['Versão do processo', 'v'.$instance->definitionVersion->version.($instance->definitionVersion->published_at ? ' — publicada em '.$this->dt($instance->definitionVersion->published_at) : '')],
            ['Solicitante (na abertura)', $instance->requester_name.($instance->requester_area ? ' — '.$instance->requester_area : '')],
            ['Aberta em', $this->dt($instance->started_at)],
            ['Encerrada em', $instance->ended_at ? $this->dt($instance->ended_at) : '—'],
            ['Status', self::STATUS[$instance->status] ?? $instance->status],
            ['Resultado', $instance->result ? str_replace('_', ' ', $instance->result) : '—'],
            ['Etapa(s) atual(is)', $openTasks->isEmpty() ? '—' : $openTasks->map(fn ($t) => $t->name.' ('.$this->group($t->candidate_group).')')->implode('; ')],
        ];
        $doc->heading('Resumo', 2)->keyValues($summary);

        // Linha do tempo e histórico de atribuição (atores capturados no momento do evento).
        $actions = array_keys(array_filter(AuditCatalog::EVENTS, fn ($e) => in_array($e[0], ['runtime', 'engine', 'integration'], true)));
        $events = AuditEvent::where('process_instance_id', $instance->id)
            ->whereIn('action', $actions) // inclui eventos anteriores à categorização
            ->orderBy('occurred_at')->limit(2000)->get();
        $timeline = [];
        $decisions = [];
        foreach ($events as $e) {
            $detail = $this->eventDetail($e);
            if ($e->action === 'instance.branch_taken') {
                $decisions[] = [$this->dt($e->occurred_at), (string) ($e->data['gateway'] ?? '—'), (string) ($e->data['path'] ?? '—'), (string) ($e->data['target'] ?? $e->stage_key ?? '—')];
            }
            $timeline[] = [$this->dt($e->occurred_at), AuditCatalog::label($e->action), (string) ($e->stage_key ?? '—'), (string) ($e->actor_name ?? 'sistema'), $detail];
        }
        $doc->heading('Linha do tempo', 2)->table(
            [['label' => 'Data/hora', 'width' => 0.16], ['label' => 'Evento', 'width' => 0.24], ['label' => 'Etapa', 'width' => 0.14], ['label' => 'Ator', 'width' => 0.2], ['label' => 'Detalhe']],
            $timeline ?: [['—', 'Sem eventos registrados', '', '', '']],
        );
        if ($decisions) {
            $doc->heading('Decisões e caminhos escolhidos', 2)->table([['label' => 'Data/hora', 'width' => 0.18], ['label' => 'Decisão'], ['label' => 'Caminho'], ['label' => 'Etapa ativada']], $decisions);
        }

        $tasks = TaskProjection::where('process_instance_id', $instance->id)->orderBy('created_at')->get();
        $taskRows = $tasks->map(fn (TaskProjection $t) => [
            $t->name, $this->group($t->candidate_group), self::TASK_STATUS[$t->status] ?? $t->status,
            $this->dt($t->engine_created_at ?? $t->created_at), $t->completed_at ? $this->dt($t->completed_at) : '—',
            $t->due_at ? $this->dt($t->due_at) : '—',
            $t->isActive() ? ($t->assignee_id ? 'Atual: '.(User::find($t->assignee_id)->name ?? '—') : 'Grupo (não assumida)') : (string) (FormSubmission::where('task_projection_id', $t->id)->latest('created_at')->value('author_name') ?? '—'),
        ])->all();
        $doc->heading('Etapas executadas', 2)->table(
            [['label' => 'Etapa', 'width' => 0.2], ['label' => 'Grupo', 'width' => 0.13], ['label' => 'Status', 'width' => 0.1], ['label' => 'Criada', 'width' => 0.13], ['label' => 'Concluída', 'width' => 0.13], ['label' => 'Prazo', 'width' => 0.13], ['label' => 'Responsável']],
            $taskRows ?: [['—', '', '', '', '', '', '']],
        );

        $data = [
            'instance' => ['number' => $instance->number, 'flow' => $instance->catalog->name, 'version' => $instance->definitionVersion->version, 'requester' => $instance->requester_name, 'status' => $instance->status, 'result' => $instance->result, 'startedAt' => $instance->started_at->toIso8601String(), 'endedAt' => $instance->ended_at?->toIso8601String()],
            'timeline' => array_map(fn ($r) => ['at' => $r[0], 'event' => $r[1], 'stage' => $r[2], 'actor' => $r[3], 'detail' => $r[4]], $timeline),
            'stages' => [],
        ];

        $attachments = Attachment::where('process_instance_id', $instance->id)->get()->keyBy('id');
        foreach ($stages->whereIn('stage_key', $selected) as $stage) {
            $submissions = FormSubmission::with('form')->where('process_instance_id', $instance->id)->where('stage_version_id', $stage->id)->orderBy('revision')->get();
            $doc->pageBreak()->heading("Etapa: {$stage->name}")->paragraph('Grupo responsável: '.$this->group($stage->lane).($stage->description ? ' · '.$stage->description : ''), 'note');
            $stageData = ['key' => $stage->stage_key, 'name' => $stage->name, 'submissions' => []];
            if ($submissions->isEmpty()) {
                $open = $tasks->first(fn ($t) => $t->stage_version_id === $stage->id && $t->isActive());
                $doc->paragraph($open ? 'Etapa em andamento: formulário ainda não submetido.' : 'Etapa não executada nesta solicitação (caminho não percorrido ou ainda não alcançado).', 'note');
            }
            foreach ($submissions as $s) {
                $schema = (array) ($s->form->schema ?? []);
                $isAuthor = $s->author_id === $viewer->id;
                $rows = $this->fieldRows((array) ($schema['components'] ?? []), (array) $s->payload, (array) $s->metadata, $canSensitive || $isAuthor, $attachments);
                $doc->heading("Submissão {$s->revision} — {$this->dt($s->created_at)}", 2)
                    ->paragraph("Por {$s->author_name} · formulário \"".($s->form->title ?? 'Formulário').'" (schema '.substr((string) $s->form_schema_hash, 0, 10).', processo v'.$instance->definitionVersion->version.')', 'small')
                    ->keyValues($rows ?: [['—', 'Formulário sem campos de dado.']]);
                $stageData['submissions'][] = ['revision' => $s->revision, 'author' => $s->author_name, 'at' => $s->created_at->toIso8601String(), 'fields' => array_map(fn ($r) => ['label' => $r[0], 'value' => $r[1]], $rows)];
            }
            $data['stages'][] = $stageData;
        }

        if ($notReached) {
            $doc->heading('Etapas do fluxo não percorridas nesta solicitação', 2)->paragraph(implode(' · ', $notReached), 'small');
        }
        if ($mode !== 'summary' && $attachments->isNotEmpty()) {
            $doc->heading('Anexos (metadados)', 2)->table(
                [['label' => 'Arquivo', 'width' => 0.45], ['label' => 'Tipo', 'width' => 0.2], ['label' => 'Tamanho', 'width' => 0.12], ['label' => 'Enviado em']],
                $attachments->values()->map(fn (Attachment $a) => [(string) $a->original_name, (string) $a->content_type, $this->size((int) $a->size), $this->dt($a->created_at)])->all(),
            );
            $doc->paragraph('O conteúdo dos anexos não é incluído no relatório; o download exige autorização no sistema.', 'small');
        }
        $doc->paragraph('Legenda: "não preenchido" = campo vazio; "não aplicável" = oculto pela regra do formulário; "restrito" = campo sensível sem permissão de visualização.', 'small');
        $doc->data = $data;

        return $doc;
    }

    /**
     * @param  list<array<string,mixed>>  $components
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $metadata
     * @param  Collection<string, Attachment>  $attachments
     * @return list<array{0:string,1:string}>
     */
    private function fieldRows(array $components, array $payload, array $metadata, bool $canSensitive, $attachments): array
    {
        $rows = [];
        foreach (FormioSchema::dataComponents($components) as $c) {
            $key = (string) ($c['key'] ?? '');
            $label = (string) ($c['label'] ?? $key);
            if (! array_key_exists($key, $payload) && ! ConditionalEvaluator::isVisible($c, $payload)) {
                $rows[] = [$label, 'não aplicável'];

                continue;
            }
            if (self::isSensitive($c) && ! $canSensitive) {
                $rows[] = [$label, '•••• restrito'];

                continue;
            }
            $value = $payload[$key] ?? null;
            $text = $this->format($c, $value, $attachments, $canSensitive);
            if (($c['type'] ?? '') === 'axlookup' && isset($metadata['lookups'][$key]['at'])) {
                $text .= ' (consulta AX '.$metadata['lookups'][$key]['transport'].' em '.$this->dt(Carbon::parse($metadata['lookups'][$key]['at'])).')';
            }
            $rows[] = [$label, $text];
        }

        return $rows;
    }

    /** @param Collection<string, Attachment> $attachments */
    private function format(array $c, mixed $value, $attachments, bool $canSensitive): string
    {
        if (ConditionalEvaluator::isEmpty($value)) {
            return 'não preenchido';
        }
        $type = (string) ($c['type'] ?? '');
        $options = [];
        foreach ((array) ($type === 'select' ? ($c['data']['values'] ?? []) : ($c['values'] ?? [])) as $o) {
            $options[(string) ($o['value'] ?? '')] = (string) ($o['label'] ?? $o['value'] ?? '');
        }

        return match ($type) {
            'checkbox' => $value ? 'Sim' : 'Não',
            'radio', 'select' => is_array($value) ? implode(', ', array_map(fn ($v) => $options[(string) $v] ?? (string) $v, $value)) : ($options[(string) $value] ?? (string) $value),
            'selectboxes' => implode(', ', array_map(fn ($k) => $options[(string) $k] ?? (string) $k, array_keys(array_filter((array) $value)))) ?: 'nenhuma opção',
            'datetime' => $this->dt(Carbon::parse((string) $value)),
            'currency' => 'R$ '.number_format((float) $value, 2, ',', '.'),
            'file' => implode('; ', array_map(function ($f) use ($attachments) {
                $id = (string) ($f['data']['attachmentId'] ?? '');
                $a = $attachments[$id] ?? null;

                return $a ? $a->original_name.' ('.$this->size((int) $a->size).')' : (string) ($f['originalName'] ?? 'arquivo');
            }, (array) $value)),
            'datagrid' => $this->grid($c, (array) $value, $attachments, $canSensitive),
            default => is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
        };
    }

    /** @param Collection<string, Attachment> $attachments */
    private function grid(array $c, array $rows, $attachments, bool $canSensitive): string
    {
        $children = FormioSchema::dataComponents((array) ($c['components'] ?? []));
        $lines = [];
        foreach (array_values($rows) as $i => $row) {
            $parts = [];
            foreach ($children as $child) {
                $k = (string) ($child['key'] ?? '');
                $v = self::isSensitive($child) && ! $canSensitive ? '•••• restrito' : $this->format($child, $row[$k] ?? null, $attachments, $canSensitive);
                $parts[] = ($child['label'] ?? $k).': '.$v;
            }
            $lines[] = ($i + 1).') '.implode('; ', $parts);
        }

        return $lines ? implode("\n", $lines) : 'nenhuma linha';
    }

    /** Campo sensível: marcado no designer (properties.tmsaSensitive) ou PII típica por chave/tipo. */
    public static function isSensitive(array $c): bool
    {
        $flag = strtolower((string) ($c['properties']['tmsaSensitive'] ?? ''));

        // tokens da chave (snake_case e camelCase): cpfMotorista → [cpf, motorista]
        $tokens = array_map('strtolower', preg_split('/_|(?<=[a-z0-9])(?=[A-Z])/', (string) ($c['key'] ?? '')) ?: []);

        return in_array($flag, ['true', '1', 'sim', 'yes'], true)
            || ($c['type'] ?? '') === 'phoneNumber'
            || array_intersect($tokens, ['cpf', 'cnpj', 'rg', 'telefone', 'celular', 'phone', 'senha', 'password']) !== [];
    }

    private function eventDetail(AuditEvent $e): string
    {
        $d = $e->data ?? [];

        return match ($e->action) {
            'task.reassigned' => 'Reatribuída'.(isset($d['justification']) ? ': '.$d['justification'] : ''),
            'task.completed' => 'Revisão '.($d['revision'] ?? 1),
            'instance.branch_taken' => ($d['gateway'] ?? '').' → '.($d['path'] ?? ''),
            'instance.ended' => 'Resultado: '.($d['result'] ?? '—'),
            'integration.failed' => 'Falha: '.($d['reason'] ?? '—'),
            'engine.task_completed', 'engine.instance_started' => ! empty($d['reconciled']) ? 'Confirmado por reconciliação' : 'Confirmado pelo engine',
            default => $e->outcome && $e->outcome !== 'success' ? 'Resultado: '.$e->outcome : '',
        };
    }

    private function group(?string $key): string
    {
        return $key ? (BpmGroup::where('key', $key)->value('name') ?? $key) : '—';
    }

    private function dt(?\DateTimeInterface $d): string
    {
        return $d ? Carbon::instance($d)->setTimezone($this->tz)->format('d/m/Y H:i') : '—';
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.').' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
