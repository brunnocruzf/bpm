<?php

use App\Models\User;
use Database\Seeders\Blueprints\For784;
use Illuminate\Database\Migrations\Migration;

/**
 * FOR-784 — Ocorrência de Ponto: raias (PONTO_SOLICITANTE, PONTO_GESTOR, RH, PONTO_INTEGRACAO), mapeamento
 * personalizado "map:integracaoPonto" (inativo até o vínculo com o endpoint) e o fluxo publicado (deploy pelo outbox).
 *
 * Só nas bases da TMSA (ADR-0019) que já têm usuários: numa instalação nova (e nos testes) não há autor
 * para o fluxo e quem instala é o DatabaseSeeder. Idempotente: não recria o que já existe.
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
        // Fluxo, raias e mapeamento podem ter instâncias e vínculos: a remoção é feita pela tela, nunca no rollback.
    }
};
