<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapeamentos personalizados (Integrações → Mapeamentos → Novo mapeamento): além das operações fixas do
 * PO 159, o administrador define novas operações do BPM — consulta nos formulários ou etapa automática
 * do fluxo — com os dados que entram e saem. A operação fica em integration_binding (operation = "map:<chave>").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_binding', function (Blueprint $table) {
            $table->boolean('custom')->default(false);
            $table->string('kind', 10)->nullable();        // personalizados: lookup | task
            $table->string('label', 120)->nullable();
            $table->string('description', 400)->nullable();
            $table->json('inputs')->nullable();            // [{key,label}] dados que a operação recebe
            $table->json('outputs')->nullable();           // [{key,label}] dados que a operação devolve ao BPM
        });
    }

    public function down(): void
    {
        Schema::table('integration_binding', function (Blueprint $table) {
            $table->dropColumn(['custom', 'kind', 'label', 'description', 'inputs', 'outputs']);
        });
    }
};
