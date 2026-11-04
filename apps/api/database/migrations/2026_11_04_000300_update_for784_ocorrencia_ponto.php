<?php

use App\Models\User;
use Database\Seeders\Blueprints\For784;
use Illuminate\Database\Migrations\Migration;

/**
 * FOR-784 — atualiza o fluxo já instalado pela 2026_11_04_000100 para o blueprint atual: grade "Ocorrência Ponto"
 * (motivo, data, entrada, saída, noturno) no painel "Dados do colaborador para ajuste do ponto" e matrícula
 * consultada no cadastro de usuários. Publica nova versão só se o blueprint difere da última versão salva
 * (instâncias abertas seguem na versão delas); sem diferença, não faz nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('bpm.organization.key') !== 'tmsa' || ! User::query()->exists()) {
            return;
        }
        For784::install();
    }

    public function down(): void
    {
        // A versão publicada pode ter instâncias: o retorno à versão anterior é feito pela tela, nunca no rollback.
    }
};
