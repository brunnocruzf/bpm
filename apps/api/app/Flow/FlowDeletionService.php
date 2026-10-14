<?php

namespace App\Flow;

use App\Models\EngineCommand;
use App\Models\ProcessCatalog;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessInstance;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Exclusão de rascunhos. Versões publicadas são imutáveis e nunca são apagadas:
 *  - excluir o FLUXO só quando ele nunca foi publicado (todas as versões em rascunho) e não tem solicitações;
 *  - descartar o RASCUNHO (versão em edição) de um fluxo que já tem versão publicada.
 * Etapas, rotas e formulários do rascunho saem junto (cascata). A trilha de auditoria guarda o resumo.
 */
class FlowDeletionService
{
    public function deleteFlow(ProcessCatalog $catalog, string $confirmKey, User $by): void
    {
        if ($confirmKey !== $catalog->key) {
            throw ApiProblem::validation(['confirm' => ['Digite o identificador do fluxo para confirmar a exclusão.']]);
        }
        DB::transaction(function () use ($catalog, $by) {
            $locked = ProcessCatalog::whereKey($catalog->id)->lockForUpdate()->firstOrFail();
            $versions = ProcessDefinitionVersion::where('catalog_id', $locked->id)->get();
            $published = $versions->first(fn (ProcessDefinitionVersion $v) => ! $v->isDraft() || $v->published_at !== null || $v->engine_definition_id !== null);
            if ($locked->current_published_version_id || $published) {
                throw ApiProblem::conflict('Este fluxo já foi publicado: versões publicadas são imutáveis e não podem ser excluídas. Descarte apenas o rascunho em edição.', 'flow_published');
            }
            if (ProcessInstance::where('catalog_id', $locked->id)->exists()) {
                throw ApiProblem::conflict('O fluxo tem solicitações registradas e não pode ser excluído.', 'flow_has_instances');
            }
            $ids = $versions->pluck('id')->all();
            if ($ids && EngineCommand::whereIn('definition_version_id', $ids)->exists()) {
                throw ApiProblem::conflict('Há comandos do engine para este fluxo; ele não pode ser excluído.', 'flow_has_engine_commands');
            }
            $summary = ['key' => $locked->key, 'name' => $locked->name, 'category' => $locked->category, 'versions' => $versions->pluck('version')->all(),
                'stages' => DB::table('process_stage_version')->whereIn('definition_version_id', $ids)->count(),
                'forms' => DB::table('form_schema_version')->whereIn('definition_version_id', $ids)->count()];
            AuditLogger::record('flow.deleted', 'process_catalog', $locked->id, $summary, null, $by, context: ['object_name' => $locked->name, 'flow_key' => $locked->key]);
            $locked->delete(); // versões, etapas, rotas e formulários em cascata
        });
    }

    public function discardDraft(ProcessCatalog $catalog, ProcessDefinitionVersion $version, User $by): void
    {
        if (! $version->isDraft() || $version->published_at !== null || $version->engine_definition_id !== null) {
            throw ApiProblem::conflict('Somente uma versão em rascunho (nunca publicada) pode ser descartada.', 'not_draft');
        }
        DB::transaction(function () use ($catalog, $version, $by) {
            $locked = ProcessCatalog::whereKey($catalog->id)->lockForUpdate()->firstOrFail();
            if (! $locked->current_published_version_id) {
                throw ApiProblem::conflict('O fluxo não tem versão publicada: exclua o fluxo inteiro em vez de descartar o único rascunho.', 'only_version');
            }
            if (ProcessInstance::where('definition_version_id', $version->id)->exists()) {
                throw ApiProblem::conflict('Há solicitações nesta versão.', 'version_has_instances');
            }
            AuditLogger::record('version.draft_discarded', 'process_definition_version', $version->id, ['version' => $version->version,
                'stages' => $version->stages()->count(), 'forms' => $version->forms()->count()], null, $by,
                context: ['object_name' => $locked->name, 'flow_key' => $locked->key, 'definition_version' => $version->version]);
            $version->delete();
            $locked->touch();
        });
    }
}
