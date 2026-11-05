<?php

use App\Models\User;
use Database\Seeders\Blueprints\For784;
use Illuminate\Database\Migrations\Migration;

/**
 * FOR-784 — publica a versão com atribuição dinâmica (ADR-0020): "Aprovar Registro de Ponto — Gestor" vai só para o
 * gestor substituto ou imediato, a correção volta ao solicitante e "O solicitante é gestor?" passa a ser calculado.
 * Publica só se o blueprint difere da última versão salva; instâncias abertas seguem na versão delas, com aprovação
 * pelo grupo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(config('bpm.organization.key'), ['tmsa', 'ims'], true) || ! User::query()->exists()) {
            return;
        }
        For784::install();
    }

    public function down(): void
    {
        // A versão publicada pode ter instâncias: o retorno à versão anterior é feito pela tela, nunca no rollback.
    }
};
