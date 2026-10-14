<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logs de integração (e-mail e Entra ID): histórico de validações/testes das configurações
 * (antes só a última ficava em app_setting.last_validation), log por etapa das execuções de
 * sincronização do diretório e log por tentativa das entregas de e-mail. Tudo já redigido (Masker).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_validation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('setting_key', 60);
            $table->string('step', 30)->nullable();
            $table->boolean('ok');
            $table->string('code', 80)->nullable();
            $table->string('message', 500)->nullable();
            $table->json('checks')->nullable();
            $table->json('details')->nullable();
            $table->string('provider', 40)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('setting_version')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_name', 200)->nullable();
            $table->string('correlation_id', 100)->nullable();
            $table->timestamp('created_at');
            $table->index(['setting_key', 'created_at']);
        });

        Schema::table('directory_sync_run', function (Blueprint $table) {
            $table->json('log')->nullable();
        });
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->json('attempt_log')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_outbox', fn (Blueprint $table) => $table->dropColumn('attempt_log'));
        Schema::table('directory_sync_run', fn (Blueprint $table) => $table->dropColumn('log'));
        Schema::dropIfExists('setting_validation');
    }
};
