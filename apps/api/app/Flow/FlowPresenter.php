<?php

namespace App\Flow;

use App\Forms\FormioSchema;
use App\Models\ProcessCatalog;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessStageVersion;

final class FlowPresenter
{
    /** @return array<string,mixed> */
    public static function catalog(ProcessCatalog $c, int $instances = 0, int $openInstances = 0): array
    {
        $published = $c->publishedVersion;
        $latest = $c->latestVersion();
        $draft = $c->draftVersion();

        return [
            'id' => $c->id,
            'key' => $c->key,
            'name' => $c->name,
            'category' => $c->category,
            'description' => $c->description,
            'status' => $c->status,
            'instancePrefix' => $c->instance_prefix,
            'initiatorGroups' => $c->initiator_groups ?? [],
            'publishedVersion' => $published ? ['version' => $published->version, 'deployed' => $published->engine_definition_id !== null, 'publishedAt' => $published->published_at?->toIso8601String()] : null,
            'latestVersion' => $latest ? ['version' => $latest->version, 'status' => $latest->status] : null,
            'hasDraft' => $draft !== null,
            'draftVersion' => $draft?->version,
            'instances' => $instances,
            'openInstances' => $openInstances,
            'updatedAt' => ($latest->updated_at ?? $c->updated_at)?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public static function version(ProcessDefinitionVersion $v): array
    {
        $g = FlowGraph::load($v);
        $fields = $g->fieldIndex();
        $labels = array_map(fn ($f) => $f['label'], $fields);
        $valueLabels = array_map(fn ($f) => $f['valueLabels'], $fields);
        $userFields = $g->userRefFields();

        return [
            'id' => $v->id,
            'version' => $v->version,
            'status' => $v->status,
            'lockVersion' => $v->lock_version,
            'hash' => $v->hash,
            'settings' => $v->settings ?? (object) [],
            'publishedAt' => $v->published_at?->toIso8601String(),
            'engineDeployed' => $v->engine_definition_id !== null,
            'validation' => $v->validation_result,
            'catalog' => [
                'key' => $v->catalog->key,
                'name' => $v->catalog->name,
                'category' => $v->catalog->category,
                'description' => $v->catalog->description,
                'instancePrefix' => $v->catalog->instance_prefix,
            ],
            'stages' => array_map(fn (ProcessStageVersion $s) => self::stage($s, $g, $labels, $valueLabels), $g->ordered()),
            'fields' => array_map(fn ($k, $f) => ['key' => $k, 'label' => $f['label'], 'type' => $f['type'], 'values' => array_map(fn ($val) => ['value' => $val, 'label' => $f['valueLabels'][$val] ?? $val], $f['values']), 'stages' => $f['stages']], array_keys($fields), $fields),
            'userFields' => array_map(fn ($k, $f) => ['key' => $k, 'label' => $f['label'], 'stages' => $f['stages']], array_keys($userFields), $userFields),
            'openingStageKey' => $g->openingStage()?->stage_key,
        ];
    }

    /** @return array<string,mixed> */
    public static function stage(ProcessStageVersion $s, FlowGraph $g, array $labels = [], array $valueLabels = []): array
    {
        $form = $s->form;

        return [
            'id' => $s->id,
            'key' => $s->stage_key,
            'elementId' => $s->element_id,
            'name' => $s->name,
            'type' => $s->stage_type,
            'lane' => $s->lane,
            'position' => $s->position,
            'description' => $s->description,
            'config' => $s->config ?: (object) [],
            'form' => $form ? [
                'id' => $form->id,
                'formKey' => $form->form_key,
                'title' => $form->title,
                'status' => $form->status,
                'fieldCount' => count(FormioSchema::dataComponents((array) ($form->schema['components'] ?? []))),
            ] : null,
            'incomingCount' => count($g->in[$s->id] ?? []),
            'outgoing' => array_map(fn ($t) => [
                'id' => $t->id,
                'elementId' => $t->element_id,
                'target' => $g->stages[$t->target_stage_id]->stage_key ?? null,
                'targetName' => $g->stages[$t->target_stage_id]->name ?? null,
                'condition' => $t->condition,
                'conditionText' => $t->is_default ? 'Caso contrário' : ConditionCompiler::describe($t->condition, $labels, $valueLabels),
                'isDefault' => $t->is_default,
                'label' => $t->label,
            ], $g->out[$s->id] ?? []),
        ];
    }
}
