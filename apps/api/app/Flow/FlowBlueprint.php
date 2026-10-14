<?php

namespace App\Flow;

use App\Forms\FormioSchema;
use App\Forms\FormioSchemaSanitizer;
use App\Models\FormSchemaVersion;
use App\Models\ProcessCatalog;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessStageVersion;
use App\Models\ProcessTransitionVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cria um fluxo completo a partir de uma especificação declarativa (seed versionado).
 * Formulários passam pelo sanitizador e o grafo pelo validador: seed inválido falha.
 */
final class FlowBlueprint
{
    /**
     * Condição "todas as regras de igualdade".
     *
     * @param  array<string,mixed>  $equals
     */
    public static function when(array $equals, string $match = 'all'): array
    {
        return [
            'match' => $match,
            'rules' => array_map(fn ($f, $v) => ['field' => $f, 'operator' => 'eq', 'value' => $v], array_keys($equals), $equals),
        ];
    }

    /** @param array<string,mixed> $spec */
    public static function create(array $spec, User $owner, bool $publish = false): ProcessCatalog
    {
        $sanitizer = app(FormioSchemaSanitizer::class);

        $catalog = DB::transaction(function () use ($spec, $owner, $sanitizer) {
            $catalog = ProcessCatalog::create([
                'key' => $spec['key'],
                'name' => $spec['name'],
                'category' => $spec['category'],
                'description' => $spec['description'] ?? null,
                'status' => 'active',
                'instance_prefix' => $spec['prefix'],
                'created_by' => $owner->id,
            ]);
            $version = ProcessDefinitionVersion::create([
                'catalog_id' => $catalog->id, 'version' => 1, 'status' => ProcessDefinitionVersion::STATUS_DRAFT,
                'settings' => $spec['settings'] ?? [], 'created_by' => $owner->id, 'updated_by' => $owner->id,
            ]);

            $ids = [];
            foreach (array_values($spec['stages']) as $position => $s) {
                $stage = ProcessStageVersion::create([
                    'definition_version_id' => $version->id,
                    'stage_key' => $s['key'],
                    'element_id' => $s['key'],
                    'name' => $s['name'],
                    'stage_type' => $s['type'],
                    'lane' => $s['lane'] ?? null,
                    'position' => $position,
                    'description' => $s['description'] ?? null,
                    'config' => $s['config'] ?? ($s['type'] === ProcessStageVersion::END ? ['result' => 'concluido'] : []),
                ]);
                if ($s['type'] === ProcessStageVersion::USER_TASK) {
                    $schema = $sanitizer->sanitize(['components' => $s['form']['components'] ?? []]);
                    $form = FormSchemaVersion::create([
                        'definition_version_id' => $version->id,
                        'stage_version_id' => $stage->id,
                        'form_key' => Keys::formKeyFor($s['key']),
                        'title' => $s['form']['title'] ?? 'Formulário da '.$s['name'],
                        'schema' => $schema,
                        'hash' => FormioSchema::hash($schema),
                        'status' => 'draft',
                    ]);
                    $stage->update(['form_schema_version_id' => $form->id]);
                }
                $ids[$s['key']] = $stage->id;
            }

            foreach (array_values($spec['transitions']) as $i => $t) {
                [$from, $to] = $t;
                $opts = $t[2] ?? [];
                ProcessTransitionVersion::create([
                    'definition_version_id' => $version->id,
                    'element_id' => 'flow_'.$from.'__'.$to,
                    'source_stage_id' => $ids[$from] ?? throw new RuntimeException("Etapa $from inexistente"),
                    'target_stage_id' => $ids[$to] ?? throw new RuntimeException("Etapa $to inexistente"),
                    'condition' => isset($opts['when']) ? ConditionCompiler::normalize($opts['when']) : null,
                    'is_default' => (bool) ($opts['default'] ?? false),
                    'label' => $opts['label'] ?? null,
                    'position' => $i,
                ]);
            }

            $problems = app(FlowValidator::class)->validate(FlowGraph::load($version));
            if (FlowValidator::hasErrors($problems)) {
                $errors = array_map(fn ($p) => $p['message'], array_filter($problems, fn ($p) => $p['severity'] === 'error'));
                throw new RuntimeException("Blueprint {$spec['key']} inválido:\n - ".implode("\n - ", $errors));
            }

            return $catalog;
        });

        if ($publish) {
            app(PublishService::class)->publish($catalog->latestVersion(), $owner);
        }

        return $catalog->fresh();
    }
}
