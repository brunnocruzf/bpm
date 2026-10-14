<?php

namespace App\Flow;

use App\Models\ProcessStageVersion;

/**
 * Layout automático em camadas (esquerda → direita) com raias horizontais,
 * para gerar o BPMN DI consumido pelo bpmn-js.
 */
final class BpmnLayout
{
    private const COL_W = 170;

    private const ROW_H = 110;

    private const POOL_HEADER = 30;

    private const LANE_HEADER = 30;

    private const ORIGIN_X = 120;

    private const ORIGIN_Y = 80;

    public const DEFAULT_LANE = '__processo';

    /** @var array<string, array{x:float,y:float,w:float,h:float}> */
    public array $shapes = [];

    /** @var array<string, array{x:float,y:float,w:float,h:float}> */
    public array $lanes = [];

    /** @var array{x:float,y:float,w:float,h:float} */
    public array $pool = ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0];

    /** @var array<string, list<array{0:float,1:float}>> */
    public array $edges = [];

    /** @var array<string,string> stageId => laneKey */
    public array $laneOf = [];

    /** @var list<string> */
    public array $laneOrder = [];

    /** @var array<string,int> */
    public array $rank = [];

    /** @var array<string,bool> transitionId => é retorno */
    public array $backEdges = [];

    public static function compute(FlowGraph $g): self
    {
        $layout = new self;
        $layout->assignLanes($g);
        $layout->assignRanks($g);
        $layout->place($g);
        $layout->route($g);

        return $layout;
    }

    public static function size(string $type): array
    {
        return match ($type) {
            ProcessStageVersion::USER_TASK, ProcessStageVersion::SERVICE_TASK => [100, 80],
            ProcessStageVersion::EXCLUSIVE_GATEWAY, ProcessStageVersion::PARALLEL_GATEWAY => [50, 50],
            default => [36, 36],
        };
    }

    private function assignLanes(FlowGraph $g): void
    {
        foreach ($g->stages as $id => $s) {
            if ($s->lane) {
                $this->laneOf[$id] = $s->lane;
            }
        }
        // Etapas sem raia (eventos/gateways) herdam do vizinho mais próximo.
        for ($pass = 0; $pass < count($g->stages); $pass++) {
            $changed = false;
            foreach ($g->ordered() as $s) {
                if (isset($this->laneOf[$s->id])) {
                    continue;
                }
                foreach ($g->in[$s->id] as $t) {
                    if (isset($this->laneOf[$t->source_stage_id])) {
                        $this->laneOf[$s->id] = $this->laneOf[$t->source_stage_id];
                        $changed = true;
                        break;
                    }
                }
                if (! isset($this->laneOf[$s->id])) {
                    foreach ($g->out[$s->id] as $t) {
                        if (isset($this->laneOf[$t->target_stage_id])) {
                            $this->laneOf[$s->id] = $this->laneOf[$t->target_stage_id];
                            $changed = true;
                            break;
                        }
                    }
                }
            }
            if (! $changed) {
                break;
            }
        }
        $firstPos = [];
        foreach ($g->ordered() as $s) {
            $lane = $this->laneOf[$s->id] ??= self::DEFAULT_LANE;
            $firstPos[$lane] ??= $s->position;
        }
        asort($firstPos);
        $this->laneOrder = array_keys($firstPos);
    }

    private function assignRanks(FlowGraph $g): void
    {
        // 1. detecção de retornos (back edges) por DFS iterativa
        $state = [];
        $roots = [];
        if ($start = $g->start()) {
            $roots[] = $start->id;
        }
        foreach ($g->ordered() as $s) {
            $roots[] = $s->id;
        }
        foreach ($roots as $root) {
            if (isset($state[$root])) {
                continue;
            }
            $stack = [[$root, 0]];
            $state[$root] = 1;
            while ($stack) {
                [$node, $i] = $stack[count($stack) - 1];
                $outs = $g->out[$node] ?? [];
                if ($i < count($outs)) {
                    $stack[count($stack) - 1][1]++;
                    $t = $outs[$i];
                    $target = $t->target_stage_id;
                    if (($state[$target] ?? 0) === 1) {
                        $this->backEdges[$t->id] = true;
                    } elseif (! isset($state[$target])) {
                        $state[$target] = 1;
                        $stack[] = [$target, 0];
                    }
                } else {
                    $state[$node] = 2;
                    array_pop($stack);
                }
            }
        }

        // 2. caminho mais longo no DAG (Kahn)
        $indeg = array_fill_keys(array_keys($g->stages), 0);
        foreach ($g->transitions as $t) {
            if (! isset($this->backEdges[$t->id]) && isset($indeg[$t->target_stage_id])) {
                $indeg[$t->target_stage_id]++;
            }
        }
        $queue = [];
        foreach ($g->ordered() as $s) {
            $this->rank[$s->id] = 0;
            if ($indeg[$s->id] === 0) {
                $queue[] = $s->id;
            }
        }
        while ($queue) {
            $node = array_shift($queue);
            foreach ($g->out[$node] ?? [] as $t) {
                if (isset($this->backEdges[$t->id])) {
                    continue;
                }
                $target = $t->target_stage_id;
                $this->rank[$target] = max($this->rank[$target], $this->rank[$node] + 1);
                if (--$indeg[$target] === 0) {
                    $queue[] = $target;
                }
            }
        }
    }

    private function place(FlowGraph $g): void
    {
        $cells = [];
        foreach ($g->ordered() as $s) {
            $cells[$this->laneOf[$s->id].'|'.$this->rank[$s->id]][] = $s->id;
        }
        $laneRows = array_fill_keys($this->laneOrder, 1);
        foreach ($cells as $key => $ids) {
            $lane = explode('|', $key)[0];
            $laneRows[$lane] = max($laneRows[$lane], count($ids));
        }

        $maxRank = $this->rank ? max($this->rank) : 0;
        $contentX0 = self::ORIGIN_X + self::POOL_HEADER + self::LANE_HEADER + 30;
        $poolW = self::POOL_HEADER + self::LANE_HEADER + 30 + ($maxRank + 1) * self::COL_W + 40;

        $laneTop = [];
        $y = self::ORIGIN_Y;
        foreach ($this->laneOrder as $lane) {
            $h = $laneRows[$lane] * self::ROW_H + 20;
            $laneTop[$lane] = $y;
            $this->lanes[$lane] = ['x' => self::ORIGIN_X + self::POOL_HEADER, 'y' => $y, 'w' => $poolW - self::POOL_HEADER, 'h' => $h];
            $y += $h;
        }
        $this->pool = ['x' => self::ORIGIN_X, 'y' => self::ORIGIN_Y, 'w' => $poolW, 'h' => $y - self::ORIGIN_Y];

        foreach ($cells as $key => $ids) {
            [$lane, $rank] = explode('|', $key);
            foreach ($ids as $row => $id) {
                [$w, $h] = self::size($g->stages[$id]->stage_type);
                $cx = $contentX0 + ((int) $rank) * self::COL_W + 50;
                $cy = $laneTop[$lane] + 10 + $row * self::ROW_H + self::ROW_H / 2;
                $this->shapes[$id] = ['x' => $cx - $w / 2, 'y' => $cy - $h / 2, 'w' => $w, 'h' => $h];
            }
        }
    }

    private function route(FlowGraph $g): void
    {
        $backIndex = 0;
        foreach ($g->transitions as $t) {
            $s = $this->shapes[$t->source_stage_id] ?? null;
            $d = $this->shapes[$t->target_stage_id] ?? null;
            if (! $s || ! $d) {
                continue;
            }
            $scx = $s['x'] + $s['w'] / 2;
            $scy = $s['y'] + $s['h'] / 2;
            $tcx = $d['x'] + $d['w'] / 2;
            $tcy = $d['y'] + $d['h'] / 2;
            $forward = ! isset($this->backEdges[$t->id]) && $this->rank[$t->target_stage_id] > $this->rank[$t->source_stage_id];

            if ($forward) {
                $isGateway = $g->stages[$t->source_stage_id]->isGateway();
                if (abs($scy - $tcy) < 1) {
                    $points = [[$s['x'] + $s['w'], $scy], [$d['x'], $tcy]];
                } elseif ($isGateway) {
                    $sy = $tcy < $scy ? $s['y'] : $s['y'] + $s['h'];
                    $points = [[$scx, $sy], [$scx, $tcy], [$d['x'], $tcy]];
                } else {
                    $midX = $s['x'] + $s['w'] + 25;
                    $points = [[$s['x'] + $s['w'], $scy], [$midX, $scy], [$midX, $tcy], [$d['x'], $tcy]];
                }
            } else {
                $yLow = max($s['y'] + $s['h'], $d['y'] + $d['h']) + 18 + 7 * ($backIndex++ % 4);
                $points = [[$scx, $s['y'] + $s['h']], [$scx, $yLow], [$tcx, $yLow], [$tcx, $d['y'] + $d['h']]];
            }
            $this->edges[$t->id] = $points;
        }
    }
}
