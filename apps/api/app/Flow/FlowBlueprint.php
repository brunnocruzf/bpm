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
        $catalog = DB::transaction(function () use ($spec, $owner) {
            $catalog = ProcessCatalog::create([
                'key' => $spec['key'],
                'name' => $spec['name'],
                'category' => $spec['category'],
                'description' => $spec['description'] ?? null,
                'status' => 'active',
                'instance_prefix' => $spec['prefix'],
                'created_by' => $owner->id,
            ]);
            self::buildVersion($catalog, 1, $spec, $owner);

            return $catalog;
        });

        if ($publish) {
            app(PublishService::class)->publish($catalog->latestVersion(), $owner);
        }

        return $catalog->fresh();
    }

    /**
     * Rascunho de nova versão de um fluxo já instalado, montado a partir da especificação atual do blueprint
     * (as instâncias em andamento seguem na versão em que foram abertas). Não sobrescreve rascunho existente.
     *
     * @param  array<string,mixed>  $spec
     */
    public static function newVersion(ProcessCatalog $catalog, array $spec, User $owner): ProcessDefinitionVersion
    {
        if ($catalog->draftVersion()) {
            throw new RuntimeException("Fluxo {$catalog->key} já tem rascunho em edição: publique ou descarte antes de atualizar pelo blueprint.");
        }

        return DB::transaction(fn () => self::buildVersion($catalog, (int) $catalog->versions()->max('version') + 1, $spec, $owner));
    }

    /**
     * Indica se a última versão salva difere da especificação (etapas, formulários e transições).
     *
     * @param  array<string,mixed>  $spec
     */
    public static function differs(ProcessCatalog $catalog, array $spec): bool
    {
        $version = $catalog->latestVersion();

        return ! $version || self::specSignature($spec) !== self::versionSignature($version);
    }

    /** @param array<string,mixed> $spec */
    private static function buildVersion(ProcessCatalog $catalog, int $number, array $spec, User $owner): ProcessDefinitionVersion
    {
        $sanitizer = app(FormioSchemaSanitizer::class);
        $version = ProcessDefinitionVersion::create([
            'catalog_id' => $catalog->id, 'version' => $number, 'status' => ProcessDefinitionVersion::STATUS_DRAFT,
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
                'config' => self::stageConfig($s),
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

        return $version;
    }

    /** @param array<string,mixed> $s */
    private static function stageConfig(array $s): array
    {
        return $s['config'] ?? ($s['type'] === ProcessStageVersion::END ? ['result' => 'concluido'] : []);
    }

    /** @param array<string,mixed> $spec */
    private static function specSignature(array $spec): string
    {
        $sanitizer = app(FormioSchemaSanitizer::class);
        $stages = [];
        foreach (array_values($spec['stages']) as $s) {
            $stages[] = [
                $s['key'], $s['type'], $s['name'], $s['lane'] ?? null, $s['description'] ?? null, self::canonical(self::stageConfig($s)),
                $s['type'] === ProcessStageVersion::USER_TASK
                    ? [$s['form']['title'] ?? 'Formulário da '.$s['name'], FormioSchema::hash($sanitizer->sanitize(['components' => $s['form']['components'] ?? []]))]
                    : null,
            ];
        }
        $transitions = [];
        foreach (array_values($spec['transitions']) as $t) {
            $opts = $t[2] ?? [];
            $transitions[] = [$t[0], $t[1], self::canonical(isset($opts['when']) ? ConditionCompiler::normalize($opts['when']) : null),
                (bool) ($opts['default'] ?? false), $opts['label'] ?? null];
        }

        return hash('sha256', json_encode([$stages, $transitions]));
    }

    private static function versionSignature(ProcessDefinitionVersion $version): string
    {
        $stages = [];
        $keys = [];
        foreach ($version->stages()->with('form')->reorder('position')->get() as $s) {
            $keys[$s->id] = $s->stage_key;
            $stages[] = [
                $s->stage_key, $s->stage_type, $s->name, $s->lane, $s->description, self::canonical((array) $s->config),
                $s->form ? [$s->form->title, $s->form->hash] : null,
            ];
        }
        $transitions = [];
        foreach ($version->transitions()->reorder('position')->get() as $t) {
            $transitions[] = [$keys[$t->source_stage_id] ?? null, $keys[$t->target_stage_id] ?? null,
                self::canonical($t->condition), (bool) $t->is_default, $t->label];
        }

        return hash('sha256', json_encode([$stages, $transitions]));
    }

    /** Ordena as chaves (o jsonb do Postgres não preserva a ordem) para comparar conteúdo. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map([self::class, 'canonical'], $value);
    }
}
