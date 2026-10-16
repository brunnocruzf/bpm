<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Grupos/raias bloqueados localmente para o usuário: vínculos que vêm do Entra (token ou sincronização) ou de
        // mapeamento continuam existindo na origem, mas não valem no BPM TMSA. Os registros com autor e data ficam em
        // bpm_group_membership (source = 'excluded'); esta coluna é o cache usado no cálculo do acesso efetivo.
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('excluded_groups')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('excluded_groups'));
    }
};
