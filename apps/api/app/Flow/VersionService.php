<?php

namespace App\Flow;

use App\Models\FormSchemaVersion;
use App\Models\ProcessCatalog;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessStageVersion;
use App\Models\ProcessTransitionVersion;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Novo rascunho a partir da última versão (artefatos publicados nunca são editados no lugar). */
class VersionService
{
    public function createDraft(ProcessCatalog $catalog, User $user): ProcessDefinitionVersion
    {
        if ($draft = $catalog->draftVersion()) {
            return $draft;
        }

        return DB::transaction(function () use ($catalog, $user) {
            $source = $catalog->latestVersion();
            $draft = ProcessDefinitionVersion::create([
                'catalog_id' => $catalog->id,
                'version' => $source->version + 1,
                'status' => ProcessDefinitionVersion::STATUS_DRAFT,
                'settings' => $source->settings ?? [],
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $map = [];
            foreach ($source->stages()->with('form')->get() as $stage) {
                $copy = ProcessStageVersion::create([
                    'definition_version_id' => $draft->id,
                    'stage_key' => $stage->stage_key,
                    'element_id' => $stage->element_id,
                    'name' => $stage->name,
                    'stage_type' => $stage->stage_type,
                    'lane' => $stage->lane,
                    'position' => $stage->position,
                    'description' => $stage->description,
                    'config' => $stage->config,
                ]);
                $map[$stage->id] = $copy->id;
                if ($stage->form) {
                    // mesma formKey (estável), novo schema independente em rascunho
                    $form = FormSchemaVersion::create([
                        'definition_version_id' => $draft->id,
                        'stage_version_id' => $copy->id,
                        'form_key' => $stage->form->form_key,
                        'title' => $stage->form->title,
                        'schema' => $stage->form->schema,
                        'hash' => $stage->form->hash,
                        'status' => 'draft',
                    ]);
                    $copy->update(['form_schema_version_id' => $form->id]);
                }
            }
            foreach ($source->transitions()->get() as $t) {
                ProcessTransitionVersion::create([
                    'definition_version_id' => $draft->id,
                    'element_id' => $t->element_id,
                    'source_stage_id' => $map[$t->source_stage_id],
                    'target_stage_id' => $map[$t->target_stage_id],
                    'label' => $t->label,
                    'condition' => $t->condition,
                    'is_default' => $t->is_default,
                    'position' => $t->position,
                ]);
            }
            AuditLogger::record('version.draft_created', 'process_definition_version', $draft->id, ['from' => $source->version, 'version' => $draft->version]);

            return $draft;
        });
    }
}
