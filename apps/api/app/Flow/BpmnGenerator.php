<?php

namespace App\Flow;

use App\Models\BpmGroup;
use App\Models\ProcessStageVersion;
use XMLWriter;

/**
 * Gera BPMN 2.0 executável (Flowable) a partir das etapas/transições da versão.
 * O XML é o artefato versionado e congelado na publicação.
 *
 * Extensões namespaced TMSA (tmsa:stage, tmsa:condition, tmsa:integration, tmsa:end)
 * preservam metadados de formulário, atribuição, condição em DSL e conectores,
 * permitindo importação de volta sem expressões livres.
 */
class BpmnGenerator
{
    public const NS_BPMN = 'http://www.omg.org/spec/BPMN/20100524/MODEL';

    public const NS_BPMNDI = 'http://www.omg.org/spec/BPMN/20100524/DI';

    public const NS_DC = 'http://www.omg.org/spec/DD/20100524/DC';

    public const NS_DI = 'http://www.omg.org/spec/DD/20100524/DI';

    public const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    public const NS_FLOWABLE = 'http://flowable.org/bpmn';

    public const NS_TMSA = 'https://tmsa.com.br/bpm/schema/1.0';

    public const EXTERNAL_TOPIC = 'tmsa-integration';

    public function generate(FlowGraph $g): string
    {
        $catalog = $g->version->catalog;
        $processId = $catalog->key;
        $layout = BpmnLayout::compute($g);
        $fieldTypes = array_map(fn ($f) => $f['type'], $g->fieldIndex());
        $groupNames = BpmGroup::pluck('name', 'key')->all();

        $w = new XMLWriter;
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('definitions');
        $w->writeAttribute('xmlns', self::NS_BPMN);
        $w->writeAttribute('xmlns:bpmndi', self::NS_BPMNDI);
        $w->writeAttribute('xmlns:dc', self::NS_DC);
        $w->writeAttribute('xmlns:di', self::NS_DI);
        $w->writeAttribute('xmlns:xsi', self::NS_XSI);
        $w->writeAttribute('xmlns:flowable', self::NS_FLOWABLE);
        $w->writeAttribute('xmlns:tmsa', self::NS_TMSA);
        $w->writeAttribute('id', 'Definitions_'.$processId);
        $w->writeAttribute('targetNamespace', 'https://tmsa.com.br/bpm/'.$processId);
        $w->writeAttribute('exporter', 'BPM TMSA');
        $w->writeAttribute('exporterVersion', (string) $g->version->version);

        // Pool
        $w->startElement('collaboration');
        $w->writeAttribute('id', 'Collaboration_'.$processId);
        $w->startElement('participant');
        $w->writeAttribute('id', 'Participant_'.$processId);
        $w->writeAttribute('name', $catalog->name);
        $w->writeAttribute('processRef', $processId);
        $w->endElement();
        $w->endElement();

        $w->startElement('process');
        $w->writeAttribute('id', $processId);
        $w->writeAttribute('name', $catalog->name);
        $w->writeAttribute('isExecutable', 'true');
        if ($catalog->description) {
            $w->writeElement('documentation', $catalog->description);
        }

        // Raias
        $w->startElement('laneSet');
        $w->writeAttribute('id', 'LaneSet_'.$processId);
        foreach ($layout->laneOrder as $lane) {
            $w->startElement('lane');
            $w->writeAttribute('id', self::laneId($lane));
            $w->writeAttribute('name', $lane === BpmnLayout::DEFAULT_LANE ? 'Processo' : ($groupNames[$lane] ?? $lane));
            foreach ($g->ordered() as $s) {
                if ($layout->laneOf[$s->id] === $lane) {
                    $w->writeElement('flowNodeRef', $s->element_id);
                }
            }
            $w->endElement();
        }
        $w->endElement();

        foreach ($g->ordered() as $s) {
            $this->writeNode($w, $s, $g);
        }

        foreach ($g->ordered() as $s) {
            foreach ($g->out[$s->id] as $t) {
                $w->startElement('sequenceFlow');
                $w->writeAttribute('id', $t->element_id);
                if ($t->label) {
                    $w->writeAttribute('name', $t->label);
                }
                $w->writeAttribute('sourceRef', $s->element_id);
                $w->writeAttribute('targetRef', $g->stages[$t->target_stage_id]->element_id);
                if ($t->condition && ! $t->is_default) {
                    $w->startElement('extensionElements');
                    $w->startElement('tmsa:condition');
                    $w->writeCdata((string) json_encode($t->condition, JSON_UNESCAPED_UNICODE));
                    $w->endElement();
                    $w->endElement();
                    $w->startElement('conditionExpression');
                    $w->writeAttribute('xsi:type', 'tFormalExpression');
                    $w->writeCdata(ConditionCompiler::compile($t->condition, $fieldTypes));
                    $w->endElement();
                }
                $w->endElement();
            }
        }
        $w->endElement(); // process

        $this->writeDiagram($w, $g, $layout, $processId);

        $w->endElement(); // definitions
        $w->endDocument();

        return $w->outputMemory();
    }

    public static function laneId(string $lane): string
    {
        return $lane === BpmnLayout::DEFAULT_LANE ? 'Lane_processo' : 'Lane_'.$lane;
    }

    private function writeNode(XMLWriter $w, ProcessStageVersion $s, FlowGraph $g): void
    {
        $config = $s->config ?? [];
        switch ($s->stage_type) {
            case ProcessStageVersion::START:
                $w->startElement('startEvent');
                $w->writeAttribute('id', $s->element_id);
                $w->writeAttribute('name', $s->name);
                $w->writeAttribute('flowable:initiator', 'initiator');
                $w->endElement();
                break;

            case ProcessStageVersion::USER_TASK:
                $w->startElement('userTask');
                $w->writeAttribute('id', $s->element_id);
                $w->writeAttribute('name', $s->name);
                if ($s->lane) {
                    $w->writeAttribute('flowable:candidateGroups', $s->lane);
                }
                if ($s->form) {
                    $w->writeAttribute('flowable:formKey', $s->form->form_key);
                }
                if (! empty($config['slaDays'])) {
                    $w->writeAttribute('flowable:dueDate', 'P'.(int) $config['slaDays'].'D');
                }
                if ($s->description) {
                    $w->writeElement('documentation', $s->description);
                }
                $w->startElement('extensionElements');
                $w->startElement('tmsa:stage');
                $w->writeAttribute('stageKey', $s->stage_key);
                if ($s->form) {
                    $w->writeAttribute('formKey', $s->form->form_key);
                    $w->writeAttribute('formSchemaVersionId', $s->form->id);
                }
                if (! empty($config['actionPlan']['mode'])) {
                    $w->writeAttribute('actionPlan', (string) $config['actionPlan']['mode']);
                }
                if (! empty($config['notifyOnComplete'])) {
                    $w->writeAttribute('notifyOnComplete', implode(',', (array) $config['notifyOnComplete']));
                }
                $notify = (array) ($config['notifyAssignee'] ?? []);
                if (! empty($notify['enabled'])) {
                    // notificação ao responsável: tratada pelo Laravel (outbox), nunca pelo engine
                    $w->writeAttribute('notifyAssignee', 'true');
                    $w->writeAttribute('notifyPolicy', (string) ($notify['groupPolicy'] ?? 'members'));
                    $w->writeAttribute('notifyOnReassign', empty($notify['onReassign']) ? 'false' : 'true');
                    $w->writeElement('tmsa:mailSubject', (string) ($notify['subject'] ?? ''));
                    $w->writeElement('tmsa:mailBody', (string) ($notify['body'] ?? ''));
                }
                $w->endElement();
                $w->endElement();
                $w->endElement();
                break;

            case ProcessStageVersion::SERVICE_TASK:
                $w->startElement('serviceTask');
                $w->writeAttribute('id', $s->element_id);
                $w->writeAttribute('name', $s->name);
                $w->writeAttribute('flowable:type', 'external-worker');
                $w->writeAttribute('flowable:topic', self::EXTERNAL_TOPIC);
                $w->startElement('extensionElements');
                $w->startElement('tmsa:integration');
                $w->writeAttribute('operation', (string) ($config['operation'] ?? ''));
                $w->endElement();
                $w->endElement();
                $w->endElement();
                break;

            case ProcessStageVersion::TIMER:
                $w->startElement('intermediateCatchEvent');
                $w->writeAttribute('id', $s->element_id);
                $w->writeAttribute('name', $s->name);
                $w->startElement('timerEventDefinition');
                if (($config['mode'] ?? 'duration') === 'date') {
                    $w->writeElement('timeDate', '${'.(string) ($config['dateField'] ?? '').'}');
                } else {
                    $w->writeElement('timeDuration', (string) ($config['duration'] ?? 'P1D'));
                }
                $w->endElement();
                $w->endElement();
                break;

            case ProcessStageVersion::EXCLUSIVE_GATEWAY:
                $w->startElement('exclusiveGateway');
                $w->writeAttribute('id', $s->element_id);
                $w->writeAttribute('name', $s->name);
                foreach ($g->out[$s->id] as $t) {
                    if ($t->is_default) {
                        $w->writeAttribute('default', $t->element_id);
                        break;
                    }
                }
                $w->endElement();
                break;

            case ProcessStageVersion::PARALLEL_GATEWAY:
                $w->startElement('parallelGateway');
                $w->writeAttribute('id', $s->element_id);
                $w->writeAttribute('name', $s->name);
                $w->endElement();
                break;

            case ProcessStageVersion::END:
                $w->startElement('endEvent');
                $w->writeAttribute('id', $s->element_id);
                $w->writeAttribute('name', $s->name);
                $w->startElement('extensionElements');
                $w->startElement('tmsa:end');
                $w->writeAttribute('result', (string) ($config['result'] ?? 'concluido'));
                if (! empty($config['notify'])) {
                    $w->writeAttribute('notify', implode(',', (array) $config['notify']));
                }
                $w->endElement();
                $w->endElement();
                if (! empty($config['terminate'])) {
                    $w->writeElement('terminateEventDefinition');
                }
                $w->endElement();
                break;
        }
    }

    private function writeDiagram(XMLWriter $w, FlowGraph $g, BpmnLayout $layout, string $processId): void
    {
        $w->startElement('bpmndi:BPMNDiagram');
        $w->writeAttribute('id', 'Diagram_'.$processId);
        $w->startElement('bpmndi:BPMNPlane');
        $w->writeAttribute('id', 'Plane_'.$processId);
        $w->writeAttribute('bpmnElement', 'Collaboration_'.$processId);

        $this->shape($w, 'Participant_'.$processId, $layout->pool, ['isHorizontal' => 'true']);
        foreach ($layout->lanes as $lane => $bounds) {
            $this->shape($w, self::laneId($lane), $bounds, ['isHorizontal' => 'true']);
        }
        foreach ($g->ordered() as $s) {
            $extra = $s->stage_type === ProcessStageVersion::EXCLUSIVE_GATEWAY ? ['isMarkerVisible' => 'true'] : [];
            $this->shape($w, $s->element_id, $layout->shapes[$s->id], $extra);
        }
        foreach ($layout->edges as $tid => $points) {
            $t = $g->transitions[$tid];
            $w->startElement('bpmndi:BPMNEdge');
            $w->writeAttribute('id', $t->element_id.'_di');
            $w->writeAttribute('bpmnElement', $t->element_id);
            foreach ($points as [$x, $y]) {
                $w->startElement('di:waypoint');
                $w->writeAttribute('x', (string) round($x));
                $w->writeAttribute('y', (string) round($y));
                $w->endElement();
            }
            $w->endElement();
        }

        $w->endElement();
        $w->endElement();
    }

    /** @param array{x:float,y:float,w:float,h:float} $b */
    private function shape(XMLWriter $w, string $element, array $b, array $attrs = []): void
    {
        $w->startElement('bpmndi:BPMNShape');
        $w->writeAttribute('id', $element.'_di');
        $w->writeAttribute('bpmnElement', $element);
        foreach ($attrs as $k => $v) {
            $w->writeAttribute($k, $v);
        }
        $w->startElement('dc:Bounds');
        $w->writeAttribute('x', (string) round($b['x']));
        $w->writeAttribute('y', (string) round($b['y']));
        $w->writeAttribute('width', (string) round($b['w']));
        $w->writeAttribute('height', (string) round($b['h']));
        $w->endElement();
        $w->endElement();
    }
}
