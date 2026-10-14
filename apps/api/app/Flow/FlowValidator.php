<?php

namespace App\Flow;

use App\Forms\FieldCatalog;
use App\Forms\FormioSchema;
use App\Models\BpmGroup;
use App\Models\ProcessStageVersion as Stage;
use App\Models\ProcessTransitionVersion;
use InvalidArgumentException;

/**
 * Validação de rascunho antes da publicação. Produz problemas acionáveis:
 *  - cada tarefa humana tem formulário válido e responsável;
 *  - nenhum formulário órfão; chaves de campo consistentes no fluxo;
 *  - gateways com condições válidas referenciando campos existentes;
 *  - todo elemento tem entrada/saída e caminho até um fim;
 *  - timers, integrações e resultados de fim configurados.
 */
class FlowValidator
{
    /** @var list<array{severity:string,code:string,message:string,stageKey:?string}> */
    private array $problems = [];

    /** @return list<array{severity:string,code:string,message:string,stageKey:?string}> */
    public function validate(FlowGraph $g): array
    {
        $this->problems = [];
        $groups = BpmGroup::pluck('key')->all();
        $fields = $g->fieldIndex();

        $starts = array_filter($g->stages, fn ($s) => $s->stage_type === Stage::START);
        $ends = array_filter($g->stages, fn ($s) => $s->stage_type === Stage::END);
        $humans = array_filter($g->stages, fn ($s) => $s->isHuman());

        if (count($starts) !== 1) {
            $this->error('start_count', 'O fluxo precisa ter exatamente um evento de início.');
        }
        if (count($ends) === 0) {
            $this->error('end_missing', 'Adicione pelo menos um evento de fim.');
        }
        if (count($humans) === 0) {
            $this->error('no_human_task', 'Adicione pelo menos uma etapa humana.');
        }

        foreach ($g->ordered() as $s) {
            $outs = $g->out[$s->id];
            $ins = $g->in[$s->id];
            $name = $s->name;

            if ($s->stage_type !== Stage::START && $ins === []) {
                $this->error('no_incoming', "\"$name\" não recebe nenhum caminho (etapa inalcançável).", $s);
            }
            if ($s->stage_type !== Stage::END && $outs === []) {
                $this->error('no_outgoing', "\"$name\" não tem saída. Defina \"O que ocorre depois?\".", $s);
            }
            if (in_array($s->stage_type, [Stage::START, Stage::USER_TASK, Stage::SERVICE_TASK, Stage::TIMER], true) && count($outs) > 1) {
                $this->error('multiple_outgoing', "\"$name\" tem mais de uma saída. Use uma decisão para ramificar.", $s);
            }
            if ($s->stage_type === Stage::END && $outs !== []) {
                $this->error('end_with_outgoing', "O fim \"$name\" não pode ter saída.", $s);
            }

            switch ($s->stage_type) {
                case Stage::USER_TASK:
                    $this->validateHuman($s, $groups);
                    break;
                case Stage::EXCLUSIVE_GATEWAY:
                    $this->validateExclusive($s, $outs, $fields, $g);
                    break;
                case Stage::PARALLEL_GATEWAY:
                    foreach ($outs as $t) {
                        if ($t->condition || $t->is_default) {
                            $this->error('parallel_condition', "Gateway paralelo \"$name\" não usa condições nas saídas.", $s);
                            break;
                        }
                    }
                    if (count($outs) < 2 && count($ins) < 2) {
                        $this->warning('parallel_useless', "Gateway paralelo \"$name\" não divide nem sincroniza caminhos.", $s);
                    }
                    break;
                case Stage::SERVICE_TASK:
                    if (! array_key_exists((string) $s->configValue('operation'), StageCatalog::SERVICE_OPERATIONS)) {
                        $this->error('service_operation', "Selecione a integração executada por \"$name\".", $s);
                    }
                    break;
                case Stage::TIMER:
                    $this->validateTimer($s, $fields);
                    break;
                case Stage::END:
                    if (! array_key_exists((string) $s->configValue('result', 'concluido'), StageCatalog::END_RESULTS)) {
                        $this->error('end_result', "Resultado de encerramento inválido em \"$name\".", $s);
                    }
                    break;
            }

            if (! $s->isHuman() && $s->form_schema_version_id) {
                $this->error('orphan_form', "\"$name\" não é tarefa humana mas possui formulário associado.", $s);
            }
            if ($s->lane && ! in_array($s->lane, $groups, true)) {
                $this->error('lane_unknown', "O grupo/raia \"{$s->lane}\" de \"$name\" não existe.", $s);
            }
        }

        $this->validateFieldConsistency($g);
        $this->validateReachability($g);

        if ($opening = $g->openingStage()) {
            $this->info('opening_form', "O formulário de \"{$opening->name}\" será usado na abertura da solicitação (botão Novo).", $opening);
        }

        return $this->problems;
    }

    /** @param list<array{severity:string}> $problems */
    public static function hasErrors(array $problems): bool
    {
        foreach ($problems as $p) {
            if ($p['severity'] === 'error') {
                return true;
            }
        }

        return false;
    }

    private function validateHuman(Stage $s, array $groups): void
    {
        if (! $s->lane) {
            $this->error('assignee_missing', "\"{$s->name}\": defina quem executa (grupo/raia).", $s);
        }
        if (! $s->form) {
            $this->error('form_missing', "\"{$s->name}\": associe um formulário.", $s);

            return;
        }
        $inputs = FormioSchema::dataComponents((array) ($s->form->schema['components'] ?? []));
        if ($inputs === []) {
            $this->error('form_empty', "\"{$s->name}\": adicione pelo menos um campo ao formulário.", $s);
        }
        $mode = $s->configValue('actionPlan.mode');
        if ($mode === 'capture') {
            $field = (string) $s->configValue('actionPlan.field', 'acoes');
            $grid = array_filter($inputs, fn ($c) => $c['key'] === $field && $c['type'] === 'datagrid');
            if (! $grid) {
                $this->error('action_plan_field', "\"{$s->name}\": o plano de ação exige um repetidor com a chave \"$field\".", $s);
            }
        }
        foreach ((array) $s->configValue('notifyOnComplete', []) as $group) {
            if (! in_array($group, $groups, true)) {
                $this->warning('notify_group', "\"{$s->name}\": grupo de notificação \"$group\" não existe.", $s);
            }
        }
        $sla = $s->configValue('slaDays');
        if ($sla !== null && $sla !== '' && (! is_numeric($sla) || (int) $sla < 1 || (int) $sla > 365)) {
            $this->error('sla_invalid', "\"{$s->name}\": prazo (SLA) deve ser entre 1 e 365 dias.", $s);
        }
    }

    /** @param list<ProcessTransitionVersion> $outs */
    private function validateExclusive(Stage $s, array $outs, array $fields, FlowGraph $g): void
    {
        if (count($outs) <= 1) {
            // Gateway de convergência (merge): uma saída sem condição.
            if (count($g->in[$s->id]) < 2) {
                $this->warning('gateway_single', "Decisão \"{$s->name}\" tem só um caminho de entrada e de saída.", $s);
            }

            return;
        }
        $defaults = array_filter($outs, fn ($t) => $t->is_default);
        if (count($defaults) > 1) {
            $this->error('multiple_defaults', "Decisão \"{$s->name}\" tem mais de um caminho padrão.", $s);
        }
        if (count($defaults) === 0) {
            $this->warning('no_default', "Decisão \"{$s->name}\" não tem caminho \"caso contrário\"; se nenhuma condição for atendida o processo para com erro.", $s);
        }
        $fieldTypes = array_map(fn ($f) => $f['type'], $fields);
        foreach ($outs as $t) {
            if ($t->is_default) {
                continue;
            }
            $target = $g->stages[$t->target_stage_id]->name ?? '?';
            if (! $t->condition) {
                $this->error('condition_missing', "Decisão \"{$s->name}\" → \"$target\": defina a condição ou marque como caminho padrão.", $s);

                continue;
            }
            try {
                ConditionCompiler::compile($t->condition, $fieldTypes);
            } catch (InvalidArgumentException $e) {
                $this->error('condition_invalid', "Decisão \"{$s->name}\" → \"$target\": {$e->getMessage()}", $s);

                continue;
            }
            foreach ($t->condition['rules'] as $rule) {
                $f = $fields[$rule['field']] ?? null;
                if (! $f) {
                    $this->error('condition_field', "Decisão \"{$s->name}\" → \"$target\": o campo \"{$rule['field']}\" não existe em nenhum formulário.", $s);

                    continue;
                }
                if (in_array($f['type'], ['datagrid', 'file', 'selectboxes'], true)) {
                    $this->error('condition_field_type', "Decisão \"{$s->name}\": o campo \"{$f['label']}\" não pode ser usado em condição.", $s);
                }
                if (in_array($rule['operator'], ['eq', 'neq'], true) && $f['values'] !== [] && ! in_array((string) $rule['value'], $f['values'], true)) {
                    $this->error('condition_value', "Decisão \"{$s->name}\": \"{$rule['value']}\" não é uma opção de \"{$f['label']}\".", $s);
                }
                if (in_array($rule['operator'], ['gt', 'gte', 'lt', 'lte'], true) && ! in_array($f['type'], ['number', 'currency'], true)) {
                    $this->error('condition_numeric', "Decisão \"{$s->name}\": maior/menor só se aplica a campos numéricos (\"{$f['label']}\").", $s);
                }
            }
        }
    }

    private function validateTimer(Stage $s, array $fields): void
    {
        $mode = $s->configValue('mode', 'duration');
        if ($mode === 'date') {
            $field = (string) $s->configValue('dateField', '');
            if (! isset($fields[$field]) || $fields[$field]['type'] !== 'datetime') {
                $this->error('timer_field', "Timer \"{$s->name}\": selecione um campo de data existente.", $s);
            }
        } elseif (! preg_match('/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/', (string) $s->configValue('duration', ''))) {
            $this->error('timer_duration', "Timer \"{$s->name}\": duração inválida (use, por exemplo, P2D ou PT4H).", $s);
        }
    }

    private function validateFieldConsistency(FlowGraph $g): void
    {
        $types = [];
        foreach ($g->ordered() as $s) {
            if (! $s->form) {
                continue;
            }
            foreach (FormioSchema::dataComponents((array) ($s->form->schema['components'] ?? [])) as $c) {
                $key = $c['key'];
                $baseType = match ($c['type']) {
                    'axlookup', 'textfield', 'textarea', 'email', 'phoneNumber' => 'text',
                    'currency' => 'number',
                    default => $c['type'],
                };
                if (isset($types[$key]) && $types[$key][0] !== $baseType) {
                    $this->error('field_type_conflict', "A chave \"$key\" é usada com tipos diferentes em \"{$types[$key][1]}\" e \"{$s->name}\". Mesma chave = mesmo dado no fluxo.", $s);
                } else {
                    $types[$key] ??= [$baseType, $s->name];
                }
                if (isset($c['properties']['tmsaAuto']) && ! FieldCatalog::isValidAuto((string) $c['properties']['tmsaAuto'])) {
                    $this->error('auto_invalid', "Campo automático inválido em \"{$s->name}\" ({$key}).", $s);
                }
            }
        }
    }

    private function validateReachability(FlowGraph $g): void
    {
        $start = $g->start();
        if (! $start) {
            return;
        }
        $reached = [$start->id => true];
        $queue = [$start->id];
        while ($queue) {
            $id = array_shift($queue);
            foreach ($g->out[$id] as $t) {
                if (! isset($reached[$t->target_stage_id])) {
                    $reached[$t->target_stage_id] = true;
                    $queue[] = $t->target_stage_id;
                }
            }
        }
        // caminho até um fim (busca reversa)
        $canEnd = [];
        $queue = [];
        foreach ($g->stages as $id => $s) {
            if ($s->stage_type === Stage::END) {
                $canEnd[$id] = true;
                $queue[] = $id;
            }
        }
        while ($queue) {
            $id = array_shift($queue);
            foreach ($g->in[$id] as $t) {
                if (! isset($canEnd[$t->source_stage_id])) {
                    $canEnd[$t->source_stage_id] = true;
                    $queue[] = $t->source_stage_id;
                }
            }
        }
        foreach ($g->ordered() as $s) {
            if (! isset($reached[$s->id]) && $g->in[$s->id] !== []) {
                $this->error('unreachable', "\"{$s->name}\" não é alcançável a partir do início.", $s);
            }
            if (isset($reached[$s->id]) && ! isset($canEnd[$s->id])) {
                $this->error('no_path_to_end', "A partir de \"{$s->name}\" não existe caminho até um fim.", $s);
            }
        }
    }

    private function error(string $code, string $message, ?Stage $s = null): void
    {
        $this->problems[] = ['severity' => 'error', 'code' => $code, 'message' => $message, 'stageKey' => $s?->stage_key];
    }

    private function warning(string $code, string $message, ?Stage $s = null): void
    {
        $this->problems[] = ['severity' => 'warning', 'code' => $code, 'message' => $message, 'stageKey' => $s?->stage_key];
    }

    private function info(string $code, string $message, ?Stage $s = null): void
    {
        $this->problems[] = ['severity' => 'info', 'code' => $code, 'message' => $message, 'stageKey' => $s?->stage_key];
    }
}
