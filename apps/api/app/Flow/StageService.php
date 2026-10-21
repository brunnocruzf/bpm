<?php

namespace App\Flow;

use App\Audit\AuditDiff;
use App\Models\BpmGroup;
use App\Models\FormSchemaVersion;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessStageVersion as Stage;
use App\Models\ProcessTransitionVersion as Transition;
use App\Models\User;
use App\Notifications\NotificationTemplate;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Edição de etapas e rotas do rascunho. Tarefa humana sempre nasce com formulário
 * vazio associado; gateways, timers, integrações e fins nunca têm formulário.
 */
class StageService
{
    private const DEFAULT_NAMES = [
        Stage::USER_TASK => 'Etapa %d',
        Stage::EXCLUSIVE_GATEWAY => 'Nova decisão',
        Stage::PARALLEL_GATEWAY => 'Caminhos paralelos',
        Stage::SERVICE_TASK => 'Nova integração',
        Stage::TIMER => 'Aguardar',
        Stage::END => 'Fim',
    ];

    private const KEY_BASES = [
        Stage::USER_TASK => 'etapa_%d',
        Stage::EXCLUSIVE_GATEWAY => 'decisao',
        Stage::PARALLEL_GATEWAY => 'paralelo',
        Stage::SERVICE_TASK => 'integracao',
        Stage::TIMER => 'timer',
        Stage::END => 'fim',
    ];

    public static function assertDraft(ProcessDefinitionVersion $version, ?int $lockVersion = null): void
    {
        if (! $version->isDraft()) {
            throw ApiProblem::conflict('Versões publicadas são imutáveis. Crie uma nova versão para editar.', 'version_not_draft');
        }
        if ($lockVersion !== null && $lockVersion !== (int) $version->lock_version) {
            throw ApiProblem::conflict('O rascunho foi alterado por outra pessoa. Recarregue para ver a versão atual.', 'stale_version', ['lockVersion' => $version->lock_version]);
        }
    }

    public function add(ProcessDefinitionVersion $version, string $type, ?string $afterStageKey, ?string $name, User $user): Stage
    {
        self::assertDraft($version);
        if (! array_key_exists($type, self::DEFAULT_NAMES)) {
            throw ApiProblem::validation(['type' => ['Tipo de etapa inválido.']]);
        }

        return DB::transaction(function () use ($version, $type, $afterStageKey, $name, $user) {
            $g = FlowGraph::load($version);
            $after = $afterStageKey ? $g->byKey($afterStageKey) : null;
            if ($afterStageKey && ! $after) {
                throw ApiProblem::notFound('Etapa de referência não encontrada.');
            }
            if (! $after) {
                // padrão: depois da última etapa não-final
                foreach (array_reverse($g->ordered()) as $s) {
                    if ($s->stage_type !== Stage::END) {
                        $after = $s;
                        break;
                    }
                }
            }

            $humanCount = count(array_filter($g->stages, fn ($s) => $s->isHuman())) + 1;
            $displayName = $name ?: sprintf(self::DEFAULT_NAMES[$type], $humanCount);
            $key = Keys::uniqueStageKey($version, sprintf(self::KEY_BASES[$type], $humanCount));
            $position = $after ? $after->position + 1 : count($g->stages);
            Stage::where('definition_version_id', $version->id)->where('position', '>=', $position)->increment('position');

            $stage = Stage::create([
                'definition_version_id' => $version->id,
                'stage_key' => $key,
                'element_id' => $key,
                'name' => mb_substr($displayName, 0, 120),
                'stage_type' => $type,
                'lane' => in_array($type, [Stage::USER_TASK, Stage::SERVICE_TASK], true) ? null : $after?->lane,
                'position' => $position,
                'config' => $this->defaultConfig($type),
            ]);
            if ($type === Stage::USER_TASK) {
                $this->createForm($version, $stage);
            }

            $this->wireNewStage($version, $g, $after, $stage);
            $this->touch($version, $user);
            AuditLogger::record('stage.added', 'process_stage_version', $stage->id, ['key' => $key, 'type' => $type]);

            return $stage->fresh('form');
        });
    }

    /** @param array<string,mixed> $attrs */
    public function update(Stage $stage, array $attrs, User $user): Stage
    {
        $version = $stage->definitionVersion;
        self::assertDraft($version, isset($attrs['lockVersion']) ? (int) $attrs['lockVersion'] : null);

        return DB::transaction(function () use ($stage, $attrs, $user, $version) {
            $before = $stage->only(['name', 'stage_type', 'lane', 'description', 'config']);
            if (array_key_exists('type', $attrs) && $attrs['type'] !== $stage->stage_type) {
                $this->changeType($stage, (string) $attrs['type']);
            }
            if (array_key_exists('name', $attrs)) {
                $name = trim((string) $attrs['name']);
                if ($name === '' || mb_strlen($name) > 120) {
                    throw ApiProblem::validation(['name' => ['Informe um nome com até 120 caracteres.']]);
                }
                $form = $stage->form;
                if ($form && $form->title === 'Formulário da '.$stage->name) {
                    $form->update(['title' => mb_substr('Formulário da '.$name, 0, 160)]);
                }
                $stage->name = $name;
            }
            if (array_key_exists('lane', $attrs)) {
                $lane = $attrs['lane'] ?: null;
                if ($lane && ! BpmGroup::where('key', $lane)->exists()) {
                    throw ApiProblem::validation(['lane' => ['Grupo/raia inexistente.']]);
                }
                $stage->lane = $lane;
            }
            if (array_key_exists('description', $attrs)) {
                $stage->description = $attrs['description'] !== null ? mb_substr(strip_tags((string) $attrs['description']), 0, 2000) : null;
            }
            if (array_key_exists('config', $attrs)) {
                $stage->config = $this->sanitizeConfig($stage->stage_type, (array) $attrs['config']);
            }
            $stage->save();
            $this->touch($version, $user);
            AuditLogger::record('stage.updated', 'process_stage_version', $stage->id, ['fields' => array_keys($attrs)],
                diff: AuditDiff::between($before, $stage->only(array_keys($before))),
                context: ['object_name' => $stage->name, 'stage_key' => $stage->stage_key, 'flow_key' => $version->catalog->key ?? null, 'definition_version' => $version->version]);

            return $stage->fresh('form');
        });
    }

    public function delete(Stage $stage, User $user): void
    {
        $version = $stage->definitionVersion;
        self::assertDraft($version);
        $g = FlowGraph::load($version);

        if ($stage->stage_type === Stage::START) {
            throw ApiProblem::conflict('O evento de início não pode ser removido.', 'cannot_delete_start');
        }
        if ($stage->isHuman() && count(array_filter($g->stages, fn ($s) => $s->isHuman())) <= 1) {
            throw ApiProblem::conflict('Um fluxo precisa manter pelo menos uma etapa humana.', 'last_human_task');
        }
        if ($stage->stage_type === Stage::END && count(array_filter($g->stages, fn ($s) => $s->stage_type === Stage::END)) <= 1) {
            throw ApiProblem::conflict('Um fluxo precisa manter pelo menos um fim.', 'last_end');
        }

        DB::transaction(function () use ($stage, $g, $version, $user) {
            $outs = $g->out[$stage->id];
            $ins = $g->in[$stage->id];
            if (! $stage->isGateway() && count($outs) === 1) {
                // reconecta predecessores ao sucessor, preservando condições
                $targetId = $outs[0]->target_stage_id;
                foreach ($ins as $t) {
                    if ($t->source_stage_id === $targetId) {
                        $t->delete();
                    } else {
                        $t->update(['target_stage_id' => $targetId]);
                    }
                }
            }
            Transition::where('source_stage_id', $stage->id)->orWhere('target_stage_id', $stage->id)->delete();
            $stage->update(['form_schema_version_id' => null]);
            FormSchemaVersion::where('stage_version_id', $stage->id)->delete();
            $stage->delete();
            $this->compactPositions($version);
            $this->touch($version, $user);
            AuditLogger::record('stage.deleted', 'process_stage_version', $stage->id, ['key' => $stage->stage_key]);
        });
    }

    /** @param list<string> $orderedKeys */
    public function reorder(ProcessDefinitionVersion $version, array $orderedKeys, User $user): void
    {
        self::assertDraft($version);
        DB::transaction(function () use ($version, $orderedKeys, $user) {
            $stages = Stage::where('definition_version_id', $version->id)->get()->keyBy('stage_key');
            $pos = 0;
            foreach ($orderedKeys as $key) {
                if ($stage = $stages->pull($key)) {
                    $stage->update(['position' => $pos++]);
                }
            }
            foreach ($stages->sortBy('position') as $stage) {
                $stage->update(['position' => $pos++]);
            }
            $this->touch($version, $user);
        });
    }

    /**
     * Substitui as saídas da etapa ("O que ocorre depois?").
     *
     * @param  array<int, array<string,mixed>>  $items  validado no controller; revalidado aqui
     */
    public function setTransitions(Stage $stage, array $items, User $user): void
    {
        $version = $stage->definitionVersion;
        self::assertDraft($version);
        if ($stage->stage_type === Stage::END) {
            throw ApiProblem::validation(['transitions' => ['Um fim não possui saídas.']]);
        }
        $g = FlowGraph::load($version);
        $errors = [];
        $normalized = [];
        $defaults = 0;
        $targets = [];

        if (! $stage->isGateway() && count($items) > 1) {
            $errors['transitions'][] = 'Esta etapa só pode ter um próximo passo. Use uma decisão para ramificar.';
        }
        foreach (array_values($items) as $i => $item) {
            $target = $g->byKey((string) ($item['target'] ?? ''));
            if (! $target) {
                $errors["transitions.$i.target"][] = 'Etapa de destino inexistente.';

                continue;
            }
            if ($target->id === $stage->id || $target->stage_type === Stage::START) {
                $errors["transitions.$i.target"][] = 'Destino inválido.';

                continue;
            }
            if (isset($targets[$target->id])) {
                $errors["transitions.$i.target"][] = 'Destino repetido.';

                continue;
            }
            $targets[$target->id] = true;
            $isDefault = (bool) ($item['isDefault'] ?? false);
            $condition = null;
            if ($stage->stage_type === Stage::EXCLUSIVE_GATEWAY && ! $isDefault) {
                try {
                    $condition = ConditionCompiler::normalize($item['condition'] ?? null);
                } catch (InvalidArgumentException $e) {
                    $errors["transitions.$i.condition"][] = $e->getMessage();
                }
            }
            if ($stage->stage_type !== Stage::EXCLUSIVE_GATEWAY) {
                $isDefault = false;
            }
            $defaults += $isDefault ? 1 : 0;
            $normalized[] = ['target' => $target, 'condition' => $condition, 'isDefault' => $isDefault, 'label' => isset($item['label']) ? mb_substr(strip_tags((string) $item['label']), 0, 120) : null];
        }
        if ($defaults > 1) {
            $errors['transitions'][] = 'Apenas um caminho pode ser "caso contrário".';
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        DB::transaction(function () use ($stage, $g, $normalized, $version, $user) {
            $previous = [];
            foreach ($g->out[$stage->id] as $t) {
                $previous[$t->target_stage_id] = $t->element_id;
                $t->delete();
            }
            foreach ($normalized as $i => $n) {
                Transition::create([
                    'definition_version_id' => $version->id,
                    'element_id' => $previous[$n['target']->id] ?? Keys::flowId($version),
                    'source_stage_id' => $stage->id,
                    'target_stage_id' => $n['target']->id,
                    'condition' => $n['condition'],
                    'is_default' => $n['isDefault'],
                    'label' => $n['label'],
                    'position' => $i,
                ]);
            }
            $this->touch($version, $user);
            AuditLogger::record('stage.transitions_updated', 'process_stage_version', $stage->id, ['count' => count($normalized)]);
        });
    }

    public function touch(ProcessDefinitionVersion $version, User $user): void
    {
        $version->forceFill(['lock_version' => $version->lock_version + 1, 'updated_by' => $user->id, 'validation_result' => null])->save();
    }

    public function createForm(ProcessDefinitionVersion $version, Stage $stage): FormSchemaVersion
    {
        $base = Keys::formKeyFor($stage->stage_key);
        $formKey = $base;
        $i = 2;
        while (FormSchemaVersion::where('definition_version_id', $version->id)->where('form_key', $formKey)->exists()) {
            $formKey = $base.'_'.$i++;
        }
        $form = FormSchemaVersion::create([
            'definition_version_id' => $version->id,
            'stage_version_id' => $stage->id,
            'form_key' => $formKey,
            'title' => mb_substr('Formulário da '.$stage->name, 0, 160),
            'schema' => FormSchemaVersion::emptySchema(),
            'status' => 'draft',
        ]);
        $stage->update(['form_schema_version_id' => $form->id]);

        return $form;
    }

    private function wireNewStage(ProcessDefinitionVersion $version, FlowGraph $g, ?Stage $after, Stage $stage): void
    {
        $firstEnd = null;
        foreach ($g->ordered() as $s) {
            if ($s->stage_type === Stage::END) {
                $firstEnd = $s;
                break;
            }
        }
        $link = fn (Stage $from, Stage $to, int $pos = 0) => Transition::create([
            'definition_version_id' => $version->id, 'element_id' => Keys::flowId($version),
            'source_stage_id' => $from->id, 'target_stage_id' => $to->id, 'position' => $pos,
        ]);

        if ($after && $after->isGateway()) {
            // nova ramificação da decisão (condição a definir)
            $link($after, $stage, count($g->out[$after->id]));
            if ($stage->stage_type !== Stage::END && $firstEnd) {
                $link($stage, $firstEnd);
            }

            return;
        }
        if ($stage->stage_type === Stage::END) {
            return;
        }
        if ($after && $after->stage_type !== Stage::END && count($g->out[$after->id]) === 1) {
            // insere na sequência: after → nova → (destino anterior)
            $old = $g->out[$after->id][0];
            $link($stage, $g->stages[$old->target_stage_id]);
            $old->update(['target_stage_id' => $stage->id]);

            return;
        }
        if ($firstEnd) {
            $link($stage, $firstEnd);
        }
    }

    private function changeType(Stage $stage, string $type): void
    {
        $allowed = [Stage::USER_TASK, Stage::EXCLUSIVE_GATEWAY, Stage::PARALLEL_GATEWAY, Stage::SERVICE_TASK, Stage::TIMER];
        if (! in_array($type, $allowed, true) || in_array($stage->stage_type, [Stage::START, Stage::END], true)) {
            throw ApiProblem::validation(['type' => ['Mudança de tipo não permitida.']]);
        }
        $g = FlowGraph::load($stage->definitionVersion);
        if ($stage->isHuman() && count(array_filter($g->stages, fn ($s) => $s->isHuman())) <= 1) {
            throw ApiProblem::conflict('Um fluxo precisa manter pelo menos uma etapa humana.', 'last_human_task');
        }
        if ($stage->isHuman()) {
            $stage->update(['form_schema_version_id' => null]);
            FormSchemaVersion::where('stage_version_id', $stage->id)->delete();
        }
        // condições só existem em decisões exclusivas
        if ($type !== Stage::EXCLUSIVE_GATEWAY) {
            Transition::where('source_stage_id', $stage->id)->update(['condition' => null, 'is_default' => false]);
        }
        $stage->stage_type = $type;
        $stage->config = $this->defaultConfig($type);
        $stage->save();
        if ($type === Stage::USER_TASK) {
            $this->createForm($stage->definitionVersion, $stage);
        }
    }

    /** @return array<string,mixed> */
    private function defaultConfig(string $type): array
    {
        return match ($type) {
            Stage::END => ['result' => 'concluido'],
            Stage::TIMER => ['mode' => 'duration', 'duration' => 'P1D'],
            Stage::SERVICE_TASK => ['operation' => null],
            default => [],
        };
    }

    /** @return array<string,mixed> */
    private function sanitizeConfig(string $type, array $config): array
    {
        $groups = fn ($list) => array_values(array_filter(array_map('strval', (array) $list), fn ($g) => preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $g)));

        return match ($type) {
            Stage::USER_TASK => array_filter([
                'notifyAssignee' => $this->notifyAssigneeConfig($config['notifyAssignee'] ?? null),
                'slaDays' => isset($config['slaDays']) && $config['slaDays'] !== '' ? (int) $config['slaDays'] : null,
                'notifyOnComplete' => $groups($config['notifyOnComplete'] ?? []) ?: null,
                'actionPlan' => in_array($config['actionPlan']['mode'] ?? null, ['capture', 'execute'], true)
                    ? ['mode' => $config['actionPlan']['mode'], 'field' => preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', (string) ($config['actionPlan']['field'] ?? '')) ? $config['actionPlan']['field'] : 'acoes']
                    : null,
            ], fn ($v) => $v !== null),
            Stage::SERVICE_TASK => ['operation' => array_key_exists((string) ($config['operation'] ?? ''), StageCatalog::serviceOperations()) ? $config['operation'] : null],
            Stage::TIMER => ($config['mode'] ?? 'duration') === 'date'
                ? ['mode' => 'date', 'dateField' => preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', (string) ($config['dateField'] ?? '')) ? $config['dateField'] : null]
                : ['mode' => 'duration', 'duration' => strtoupper(substr((string) ($config['duration'] ?? 'P1D'), 0, 20))],
            Stage::END => array_filter([
                'result' => array_key_exists((string) ($config['result'] ?? ''), StageCatalog::END_RESULTS) ? $config['result'] : 'concluido',
                'terminate' => ! empty($config['terminate']) ?: null,
                'notify' => $groups($config['notify'] ?? []) ?: null,
            ], fn ($v) => $v !== null),
            default => [],
        };
    }

    /**
     * "Enviar e-mail ao responsável quando esta etapa receber uma nova tarefa" (desligado por padrão).
     *
     * @return array<string,mixed>|null
     */
    private function notifyAssigneeConfig(mixed $cfg): ?array
    {
        if (! is_array($cfg)) {
            return null;
        }
        $template = NotificationTemplate::validate($cfg['subject'] ?? null, $cfg['body'] ?? null);

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'groupPolicy' => in_array($cfg['groupPolicy'] ?? null, ['members', 'group_mailbox'], true) ? $cfg['groupPolicy'] : 'members',
            'onReassign' => (bool) ($cfg['onReassign'] ?? true),
            'subject' => $template['subject'],
            'body' => $template['body'],
        ];
    }

    private function compactPositions(ProcessDefinitionVersion $version): void
    {
        foreach (Stage::where('definition_version_id', $version->id)->orderBy('position')->get()->values() as $i => $s) {
            if ($s->position !== $i) {
                $s->update(['position' => $i]);
            }
        }
    }
}
