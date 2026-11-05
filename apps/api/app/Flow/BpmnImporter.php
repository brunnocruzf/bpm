<?php

namespace App\Flow;

use App\Models\BpmGroup;
use App\Models\FormSchemaVersion;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessStageVersion as Stage;
use App\Models\ProcessTransitionVersion;
use App\Models\User;
use App\Notifications\NotificationTemplate;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Importa BPMN XML (ex.: editado no modelador bpmn-js) para etapas/rotas do rascunho.
 * Aceita somente o subset certificado; formulários são preservados pelo element_id.
 * Não aceita scripts, listeners, classes Java nem expressões livres.
 */
class BpmnImporter
{
    private const SUPPORTED = ['startEvent', 'endEvent', 'userTask', 'serviceTask', 'exclusiveGateway', 'parallelGateway', 'intermediateCatchEvent', 'sequenceFlow', 'laneSet', 'documentation', 'extensionElements'];

    private const LABELS = [
        'task' => 'Tarefa genérica', 'scriptTask' => 'Tarefa de script', 'manualTask' => 'Tarefa manual', 'sendTask' => 'Tarefa de envio',
        'receiveTask' => 'Tarefa de recebimento', 'businessRuleTask' => 'Regra de negócio', 'subProcess' => 'Subprocesso', 'callActivity' => 'Call activity',
        'inclusiveGateway' => 'Gateway inclusivo', 'eventBasedGateway' => 'Gateway baseado em eventos', 'complexGateway' => 'Gateway complexo',
        'boundaryEvent' => 'Evento de borda', 'intermediateThrowEvent' => 'Evento intermediário de disparo', 'dataObjectReference' => 'Objeto de dados',
        'dataStoreReference' => 'Repositório de dados', 'textAnnotation' => 'Anotação', 'association' => 'Associação', 'adHocSubProcess' => 'Subprocesso ad hoc',
        'transaction' => 'Transação',
    ];

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @return array{created:list<string>,updated:int,removed:list<string>} */
    public function import(ProcessDefinitionVersion $version, string $xml, User $user): array
    {
        StageService::assertDraft($version);
        $this->errors = [];
        [$nodes, $flows] = $this->parse($xml);
        if ($this->errors) {
            throw ApiProblem::validation($this->errors, 'O BPMN contém elementos ou configurações não suportados nesta versão do produto.');
        }

        return DB::transaction(function () use ($version, $nodes, $flows, $user) {
            $existing = Stage::where('definition_version_id', $version->id)->with('form')->get()->keyBy('element_id');
            $stageService = app(StageService::class);
            $summary = ['created' => [], 'updated' => 0, 'removed' => []];
            $ids = [];

            ProcessTransitionVersion::where('definition_version_id', $version->id)->delete();

            foreach ($nodes as $node) {
                /** @var Stage|null $stage */
                $stage = $existing->pull($node['id']);
                if ($stage) {
                    if ($stage->isHuman() && $node['type'] !== Stage::USER_TASK) {
                        $stage->update(['form_schema_version_id' => null]);
                        FormSchemaVersion::where('stage_version_id', $stage->id)->delete();
                    }
                    $stage->fill(['name' => $node['name'], 'stage_type' => $node['type'], 'lane' => $node['lane'], 'position' => $node['position'], 'config' => $node['config']]);
                    if ($node['documentation'] !== null) {
                        $stage->description = $node['documentation'];
                    }
                    $stage->save();
                    if ($node['type'] === Stage::USER_TASK && ! $stage->form_schema_version_id) {
                        $stageService->createForm($version, $stage);
                    }
                    $summary['updated']++;
                } else {
                    $stage = Stage::create([
                        'definition_version_id' => $version->id, 'stage_key' => $node['id'], 'element_id' => $node['id'],
                        'name' => $node['name'], 'stage_type' => $node['type'], 'lane' => $node['lane'], 'position' => $node['position'],
                        'description' => $node['documentation'], 'config' => $node['config'],
                    ]);
                    if ($node['type'] === Stage::USER_TASK) {
                        $stageService->createForm($version, $stage);
                    }
                    $summary['created'][] = $node['name'];
                }
                $ids[$node['id']] = $stage->id;
            }

            foreach ($existing as $removed) {
                $removed->update(['form_schema_version_id' => null]);
                FormSchemaVersion::where('stage_version_id', $removed->id)->delete();
                $removed->delete();
                $summary['removed'][] = $removed->name;
            }

            foreach ($flows as $i => $f) {
                ProcessTransitionVersion::create([
                    'definition_version_id' => $version->id, 'element_id' => $f['id'],
                    'source_stage_id' => $ids[$f['source']], 'target_stage_id' => $ids[$f['target']],
                    'label' => $f['name'], 'condition' => $f['condition'], 'is_default' => $f['isDefault'], 'position' => $i,
                ]);
            }

            $stageService->touch($version, $user);
            AuditLogger::record('version.bpmn_imported', 'process_definition_version', $version->id, ['created' => count($summary['created']), 'removed' => count($summary['removed'])]);

            return $summary;
        });
    }

    /**
     * Registra problemas em $this->errors (efeito colateral).
     *
     * @phpstan-impure
     *
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function parse(string $xml): array
    {
        if (strlen($xml) > 2 * 1024 * 1024) {
            $this->err('xml', 'Arquivo BPMN acima de 2 MB.');

            return [[], []];
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            $this->err('xml', 'DTD/entidades não são permitidas no BPMN.');

            return [[], []];
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $ok) {
            $this->err('xml', 'XML inválido.');

            return [[], []];
        }

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('b', BpmnGenerator::NS_BPMN);
        $xp->registerNamespace('bpmndi', BpmnGenerator::NS_BPMNDI);
        $xp->registerNamespace('dc', BpmnGenerator::NS_DC);
        $xp->registerNamespace('flowable', BpmnGenerator::NS_FLOWABLE);
        $xp->registerNamespace('tmsa', BpmnGenerator::NS_TMSA);

        $processes = $xp->query('/b:definitions/b:process');
        if ($processes->length !== 1) {
            $this->err('xml', 'O arquivo deve conter exatamente um processo (pool).');

            return [[], []];
        }
        /** @var DOMElement $process */
        $process = $processes->item(0);

        foreach ($xp->query('//flowable:executionListener | //flowable:taskListener | //flowable:eventListener | //b:script | //flowable:field') as $el) {
            $this->err('xml', 'Listeners, scripts e campos injetados não são permitidos.');
            break;
        }

        // Raias → grupos
        $groups = BpmGroup::all();
        $laneOf = [];
        foreach ($xp->query('b:laneSet/b:lane', $process) as $lane) {
            /** @var DOMElement $lane */
            $key = $this->resolveLane($lane, $groups);
            foreach ($xp->query('b:flowNodeRef', $lane) as $ref) {
                $laneOf[trim($ref->textContent)] = $key;
            }
        }

        // Posição visual pelo DI (x), se existir
        $xPos = [];
        foreach ($xp->query('//bpmndi:BPMNShape') as $shape) {
            /** @var DOMElement $shape */
            $bounds = $xp->query('dc:Bounds', $shape)->item(0);
            if ($bounds instanceof DOMElement) {
                $xPos[$shape->getAttribute('bpmnElement')] = [(float) $bounds->getAttribute('x'), (float) $bounds->getAttribute('y')];
            }
        }

        $nodes = [];
        $flows = [];
        $defaults = [];
        $order = 0;
        foreach ($process->childNodes as $el) {
            if (! $el instanceof DOMElement) {
                continue;
            }
            $tag = $el->localName;
            $id = $el->getAttribute('id');
            $name = trim($el->getAttribute('name')) ?: $id;
            if (! in_array($tag, self::SUPPORTED, true)) {
                $label = self::LABELS[$tag] ?? $tag;
                $this->err($id ?: $tag, "Elemento \"$label\" ($name) não é suportado nesta versão do produto.");

                continue;
            }
            if (in_array($tag, ['laneSet', 'documentation', 'extensionElements'], true)) {
                continue;
            }
            if ($tag !== 'sequenceFlow' && ! preg_match('/^[A-Za-z_][A-Za-z0-9_\-]{0,63}$/', $id)) {
                $this->err($id ?: $tag, "Identificador BPMN inválido: \"$id\".");

                continue;
            }

            if ($tag === 'sequenceFlow') {
                $flows[] = $this->parseFlow($el, $xp);

                continue;
            }

            $node = [
                'id' => $id,
                'name' => mb_substr($name, 0, 120),
                'lane' => $laneOf[$id] ?? null,
                'documentation' => ($doc = $xp->query('b:documentation', $el)->item(0)) ? mb_substr(trim($doc->textContent), 0, 2000) : null,
                'config' => [],
                'order' => $order++,
            ];
            foreach (['class', 'expression', 'delegateExpression', 'assignee', 'candidateUsers'] as $attr) {
                if ($el->hasAttributeNS(BpmnGenerator::NS_FLOWABLE, $attr)) {
                    $this->err($id, "\"$name\": atributo flowable:$attr não é permitido (use grupos e integrações do catálogo).");
                }
            }

            switch ($tag) {
                case 'startEvent':
                    $node['type'] = Stage::START;
                    if ($this->hasEventDefinition($el)) {
                        $this->err($id, 'Somente evento de início simples é suportado.');
                    }
                    break;
                case 'endEvent':
                    $node['type'] = Stage::END;
                    $end = $xp->query('b:extensionElements/tmsa:end', $el)->item(0);
                    $result = $end instanceof DOMElement ? $end->getAttribute('result') : 'concluido';
                    $node['config'] = array_filter([
                        'result' => array_key_exists($result, StageCatalog::END_RESULTS) ? $result : 'concluido',
                        'terminate' => $xp->query('b:terminateEventDefinition', $el)->length > 0 ?: null,
                        'notify' => $end instanceof DOMElement && $end->getAttribute('notify') !== '' ? explode(',', $end->getAttribute('notify')) : null,
                    ], fn ($v) => $v !== null);
                    foreach (['messageEventDefinition', 'errorEventDefinition', 'signalEventDefinition', 'escalationEventDefinition', 'compensateEventDefinition'] as $def) {
                        if ($xp->query("b:$def", $el)->length) {
                            $this->err($id, "Fim \"$name\": tipo de evento não suportado.");
                        }
                    }
                    break;
                case 'userTask':
                    $node['type'] = Stage::USER_TASK;
                    $groupsAttr = $el->getAttributeNS(BpmnGenerator::NS_FLOWABLE, 'candidateGroups');
                    if (! $node['lane'] && $groupsAttr !== '' && $groups->firstWhere('key', $groupsAttr)) {
                        $node['lane'] = $groupsAttr;
                    }
                    $due = $el->getAttributeNS(BpmnGenerator::NS_FLOWABLE, 'dueDate');
                    $tm = $xp->query('b:extensionElements/tmsa:stage', $el)->item(0);
                    $assignment = $xp->query('b:extensionElements/tmsa:assignment', $el)->item(0);
                    $node['config'] = array_filter([
                        'slaDays' => preg_match('/^P(\d{1,3})D$/', $due, $m) ? (int) $m[1] : null,
                        'actionPlan' => $tm instanceof DOMElement && in_array($tm->getAttribute('actionPlan'), ['capture', 'execute'], true) ? ['mode' => $tm->getAttribute('actionPlan'), 'field' => 'acoes'] : null,
                        'notifyOnComplete' => $tm instanceof DOMElement && $tm->getAttribute('notifyOnComplete') !== '' ? explode(',', $tm->getAttribute('notifyOnComplete')) : null,
                        'notifyAssignee' => $tm instanceof DOMElement && $tm->getAttribute('notifyAssignee') === 'true' ? $this->notifyConfig($xp, $tm, $id, $name) : null,
                        'assignment' => $assignment ? $this->assignmentConfig($assignment->textContent, $id, $name) : null,
                    ], fn ($v) => $v !== null);
                    break;
                case 'serviceTask':
                    $node['type'] = Stage::SERVICE_TASK;
                    $integration = $xp->query('b:extensionElements/tmsa:integration', $el)->item(0);
                    $op = $integration instanceof DOMElement ? $integration->getAttribute('operation') : '';
                    if ($el->getAttributeNS(BpmnGenerator::NS_FLOWABLE, 'type') !== 'external-worker' || ! array_key_exists($op, StageCatalog::serviceOperations())) {
                        $this->err($id, "Integração \"$name\": selecione uma operação do catálogo de integrações.");
                    }
                    $node['config'] = ['operation' => $op ?: null];
                    break;
                case 'intermediateCatchEvent':
                    $node['type'] = Stage::TIMER;
                    $timer = $xp->query('b:timerEventDefinition', $el)->item(0);
                    if (! $timer) {
                        $this->err($id, "Evento intermediário \"$name\": somente timer é suportado.");
                        break;
                    }
                    $duration = $xp->query('b:timeDuration', $timer)->item(0);
                    $date = $xp->query('b:timeDate', $timer)->item(0);
                    if ($duration) {
                        $node['config'] = ['mode' => 'duration', 'duration' => trim($duration->textContent)];
                    } elseif ($date && preg_match('/^\$\{([A-Za-z][A-Za-z0-9_]{0,62})\}$/', trim($date->textContent), $m)) {
                        $node['config'] = ['mode' => 'date', 'dateField' => $m[1]];
                    } else {
                        $this->err($id, "Timer \"$name\": use duração ISO (ex.: P2D) ou um campo de data (\${campo}).");
                    }
                    break;
                case 'exclusiveGateway':
                    $node['type'] = Stage::EXCLUSIVE_GATEWAY;
                    if ($el->getAttribute('default') !== '') {
                        $defaults[$el->getAttribute('default')] = true;
                    }
                    break;
                case 'parallelGateway':
                    $node['type'] = Stage::PARALLEL_GATEWAY;
                    break;
            }
            $nodes[$id] = $node;
        }

        $starts = array_filter($nodes, fn ($n) => $n['type'] === Stage::START);
        if (count($starts) !== 1) {
            $this->err('xml', 'O processo deve ter exatamente um evento de início.');
        }

        foreach ($flows as $i => $f) {
            if (! isset($nodes[$f['source']], $nodes[$f['target']])) {
                $this->err($f['id'], "Sequence flow \"{$f['id']}\" liga elementos inexistentes ou não suportados.");
            }
            $flows[$i]['isDefault'] = isset($defaults[$f['id']]);
            if ($flows[$i]['isDefault']) {
                $flows[$i]['condition'] = null;
            }
        }

        // ordem visual: x do DI, depois ordem no documento
        uasort($nodes, function ($a, $b) use ($xPos) {
            $pa = $xPos[$a['id']] ?? [PHP_FLOAT_MAX, 0];
            $pb = $xPos[$b['id']] ?? [PHP_FLOAT_MAX, 0];

            return [$pa[0], $pa[1], $a['order']] <=> [$pb[0], $pb[1], $b['order']];
        });
        $position = 0;
        foreach ($nodes as $id => $n) {
            $nodes[$id]['position'] = $position++;
        }

        return [array_values($nodes), $flows];
    }

    /** @return array<string,mixed> */
    private function parseFlow(DOMElement $el, DOMXPath $xp): array
    {
        $id = $el->getAttribute('id');
        $condition = null;
        $tm = $xp->query('b:extensionElements/tmsa:condition', $el)->item(0);
        $expr = $xp->query('b:conditionExpression', $el)->item(0);
        if ($tm) {
            try {
                $condition = ConditionCompiler::normalize(json_decode(trim($tm->textContent), true, 8, JSON_THROW_ON_ERROR));
            } catch (InvalidArgumentException|\JsonException $e) {
                $this->err($id, 'Condição inválida: '.$e->getMessage());
            }
        } elseif ($expr && trim($expr->textContent) !== '') {
            $this->err($id, 'Condições em expressão livre não são permitidas. Configure a condição no editor de roteamento.');
        }

        return [
            'id' => preg_match('/^[A-Za-z_][A-Za-z0-9_\-]{0,63}$/', $id) ? $id : 'flow_'.substr(md5($id), 0, 8),
            'name' => $el->getAttribute('name') ? mb_substr($el->getAttribute('name'), 0, 120) : null,
            'source' => $el->getAttribute('sourceRef'),
            'target' => $el->getAttribute('targetRef'),
            'condition' => $condition,
            'isDefault' => false,
        ];
    }

    private function resolveLane(DOMElement $lane, $groups): ?string
    {
        $id = $lane->getAttribute('id');
        $name = trim($lane->getAttribute('name'));
        if ($id === 'Lane_processo') {
            return null;
        }
        $candidate = str_starts_with($id, 'Lane_') ? substr($id, 5) : null;
        foreach ($groups as $g) {
            if ($g->key === $candidate || strcasecmp($g->key, $name) === 0 || strcasecmp($g->name, $name) === 0) {
                return $g->key;
            }
        }
        $this->err($id, "A raia \"$name\" não corresponde a nenhum grupo cadastrado.");

        return null;
    }

    private function hasEventDefinition(DOMElement $el): bool
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement && str_ends_with($c->localName, 'EventDefinition')) {
                return true;
            }
        }

        return false;
    }

    private function err(string $key, string $message): void
    {
        $this->errors[$key][] = $message;
    }

    /** @return array<string,mixed>|null regra de atribuição da etapa */
    private function assignmentConfig(string $json, string $id, string $name): ?array
    {
        try {
            $rule = json_decode(trim($json), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $rule = false;
        }
        if (! is_array($rule) || ! AssignmentRule::isValidMode($rule)) {
            $this->err($id, "\"$name\": regra de atribuição inválida.");

            return null;
        }

        return AssignmentRule::normalize($rule);
    }

    /** @return array<string,mixed>|null configuração de notificação da etapa (template validado) */
    private function notifyConfig(DOMXPath $xp, DOMElement $tm, string $id, string $name): ?array
    {
        $subject = (string) ($xp->query('tmsa:mailSubject', $tm)->item(0)->textContent ?? '');
        $body = (string) ($xp->query('tmsa:mailBody', $tm)->item(0)->textContent ?? '');
        try {
            $template = NotificationTemplate::validate($subject, $body);
        } catch (ApiProblem $e) {
            $this->err($id, "Notificação de \"$name\": template inválido (".implode(' ', array_merge(...array_values($e->errors))).').');

            return null;
        }

        return [
            'enabled' => true,
            'groupPolicy' => in_array($tm->getAttribute('notifyPolicy'), ['members', 'group_mailbox'], true) ? $tm->getAttribute('notifyPolicy') : 'members',
            'onReassign' => $tm->getAttribute('notifyOnReassign') !== 'false',
            ...$template,
        ];
    }
}
