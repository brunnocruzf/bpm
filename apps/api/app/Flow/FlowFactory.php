<?php

namespace App\Flow;

use App\Models\FormSchemaVersion;
use App\Models\ProcessCatalog;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessStageVersion as Stage;
use App\Models\ProcessTransitionVersion;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Regra obrigatória de criação (seção 4.3): em UMA transação, cria
 *  (a) fluxo, (b) versão 1 em rascunho, (c) etapa humana "Etapa 1" ligada ao Start,
 *  (d) formulário vazio "Formulário da Etapa 1" associado e (e) caminho Start → Etapa 1 → Fim.
 * Qualquer falha reverte tudo: nunca existe fluxo sem etapa/formulário inicial.
 */
class FlowFactory
{
    /** @param array{name:string,key?:?string,category:string,description?:?string,instancePrefix?:?string} $input */
    public function create(array $input, User $user): ProcessCatalog
    {
        $key = $input['key'] ?? null;
        $suggested = $key === null || $key === '';
        $key = $suggested ? Keys::slug($input['name']) : $key;

        if (! preg_match('/^[a-z][a-z0-9_]{2,63}$/', $key)) {
            throw ApiProblem::validation(['key' => ['Chave inválida: use minúsculas, números e _ (3 a 64 caracteres, iniciando por letra).']]);
        }
        if (ProcessCatalog::where('key', $key)->exists()) {
            if (! $suggested) {
                throw ApiProblem::conflict("Já existe um fluxo com a chave \"$key\".", 'duplicate_key');
            }
            $base = substr($key, 0, 58);
            $i = 2;
            while (ProcessCatalog::where('key', "{$base}_$i")->exists()) {
                $i++;
            }
            $key = "{$base}_$i";
        }

        return DB::transaction(function () use ($input, $user, $key) {
            $catalog = ProcessCatalog::create([
                'key' => $key,
                'name' => $input['name'],
                'category' => $input['category'],
                'description' => $input['description'] ?? null,
                'status' => 'active',
                'instance_prefix' => strtoupper($input['instancePrefix'] ?? '') ?: Keys::instancePrefix($input['name']),
                'created_by' => $user->id,
            ]);

            $version = ProcessDefinitionVersion::create([
                'catalog_id' => $catalog->id,
                'version' => 1,
                'status' => ProcessDefinitionVersion::STATUS_DRAFT,
                'settings' => [],
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $start = Stage::create([
                'definition_version_id' => $version->id, 'stage_key' => 'inicio', 'element_id' => 'inicio',
                'name' => 'Início', 'stage_type' => Stage::START, 'position' => 0, 'config' => [],
            ]);
            $stage = Stage::create([
                'definition_version_id' => $version->id, 'stage_key' => 'etapa_1', 'element_id' => 'etapa_1',
                'name' => 'Etapa 1', 'stage_type' => Stage::USER_TASK, 'position' => 1, 'config' => [],
            ]);
            $end = Stage::create([
                'definition_version_id' => $version->id, 'stage_key' => 'fim', 'element_id' => 'fim',
                'name' => 'Fim', 'stage_type' => Stage::END, 'position' => 2, 'config' => ['result' => 'concluido'],
            ]);

            $form = FormSchemaVersion::create([
                'definition_version_id' => $version->id,
                'stage_version_id' => $stage->id,
                'form_key' => Keys::formKeyFor('etapa_1'),
                'title' => 'Formulário da Etapa 1',
                'schema' => FormSchemaVersion::emptySchema(),
                'status' => 'draft',
            ]);
            $stage->update(['form_schema_version_id' => $form->id]);

            ProcessTransitionVersion::create(['definition_version_id' => $version->id, 'element_id' => 'flow_inicio_etapa_1', 'source_stage_id' => $start->id, 'target_stage_id' => $stage->id, 'position' => 0]);
            ProcessTransitionVersion::create(['definition_version_id' => $version->id, 'element_id' => 'flow_etapa_1_fim', 'source_stage_id' => $stage->id, 'target_stage_id' => $end->id, 'position' => 0]);

            AuditLogger::record('flow.created', 'process_catalog', $catalog->id, ['key' => $key, 'version' => 1], null, $user);

            return $catalog;
        });
    }
}
