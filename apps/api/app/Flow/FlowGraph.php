<?php

namespace App\Flow;

use App\Forms\FormioSchema;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessStageVersion;
use App\Models\ProcessTransitionVersion;

/** Grafo em memória de uma versão do fluxo (etapas + sequence flows). */
final class FlowGraph
{
    /** @var array<string, ProcessStageVersion> por id */
    public array $stages = [];

    /** @var array<string, ProcessTransitionVersion> por id */
    public array $transitions = [];

    /** @var array<string, list<ProcessTransitionVersion>> */
    public array $out = [];

    /** @var array<string, list<ProcessTransitionVersion>> */
    public array $in = [];

    public function __construct(public readonly ProcessDefinitionVersion $version) {}

    public static function load(ProcessDefinitionVersion $version): self
    {
        $graph = new self($version);
        $stages = $version->stages()->with('form')->get();
        foreach ($stages as $stage) {
            $graph->stages[$stage->id] = $stage;
            $graph->out[$stage->id] = [];
            $graph->in[$stage->id] = [];
        }
        foreach ($version->transitions()->get() as $t) {
            $graph->transitions[$t->id] = $t;
            if (isset($graph->stages[$t->source_stage_id], $graph->stages[$t->target_stage_id])) {
                $graph->out[$t->source_stage_id][] = $t;
                $graph->in[$t->target_stage_id][] = $t;
            }
        }
        foreach ($graph->out as $id => $list) {
            usort($list, fn ($a, $b) => [$a->is_default, $a->position] <=> [$b->is_default, $b->position]);
            $graph->out[$id] = $list;
        }

        return $graph;
    }

    public function start(): ?ProcessStageVersion
    {
        foreach ($this->stages as $s) {
            if ($s->stage_type === ProcessStageVersion::START) {
                return $s;
            }
        }

        return null;
    }

    public function byKey(string $key): ?ProcessStageVersion
    {
        foreach ($this->stages as $s) {
            if ($s->stage_key === $key) {
                return $s;
            }
        }

        return null;
    }

    /** Primeira tarefa humana logo após o início (formulário de abertura), se houver. */
    public function openingStage(): ?ProcessStageVersion
    {
        $start = $this->start();
        if (! $start || count($this->out[$start->id]) !== 1) {
            return null;
        }
        $target = $this->stages[$this->out[$start->id][0]->target_stage_id] ?? null;

        return $target && $target->isHuman() ? $target : null;
    }

    /**
     * Índice de campos de todos os formulários: key => [type, label, values, stageKeys].
     *
     * @return array<string, array{type:string,label:string,values:list<string>,valueLabels:array<string,string>,stages:list<string>}>
     */
    public function fieldIndex(): array
    {
        $index = [];
        foreach ($this->stages as $stage) {
            if (! $stage->form) {
                continue;
            }
            foreach (FormioSchema::dataComponents((array) ($stage->form->schema['components'] ?? [])) as $c) {
                $key = $c['key'];
                if (! isset($index[$key])) {
                    $index[$key] = ['type' => $c['type'], 'label' => (string) ($c['label'] ?? $key), 'values' => FormioSchema::optionValues($c), 'valueLabels' => self::valueLabels($c), 'stages' => []];
                }
                $index[$key]['stages'][] = $stage->stage_key;
            }
        }

        return $index;
    }

    /** @return array<string,string> */
    private static function valueLabels(array $c): array
    {
        $list = match ($c['type'] ?? '') {
            'radio', 'selectboxes' => (array) ($c['values'] ?? []),
            'select' => (array) ($c['data']['values'] ?? []),
            default => [],
        };
        $out = [];
        foreach ($list as $o) {
            $out[(string) ($o['value'] ?? '')] = (string) ($o['label'] ?? $o['value'] ?? '');
        }

        return $out;
    }

    /** @return list<ProcessStageVersion> etapas ordenadas por posição */
    public function ordered(): array
    {
        $list = array_values($this->stages);
        usort($list, fn ($a, $b) => $a->position <=> $b->position);

        return $list;
    }
}
