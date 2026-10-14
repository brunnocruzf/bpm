<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importação/sincronização do diretório Entra (Microsoft Graph, app-only, somente leitura).
 * Usuários continuam em `users` (chave estável tenant + oid); o diretório só acrescenta
 * atributos, origem e estado. Nada é apagado: desativação preserva autoria e histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('upn', 200)->nullable();
            $table->string('department', 200)->nullable();
            $table->string('user_type', 20)->nullable(); // Member | Guest
            $table->boolean('account_enabled')->nullable();
            // Papéis/grupos por origem; roles/groups continuam sendo os efetivos (união).
            $table->jsonb('token_roles')->nullable();
            $table->jsonb('token_groups')->nullable();
            $table->jsonb('directory_roles')->nullable();
            $table->jsonb('directory_groups')->nullable();
            $table->timestamp('directory_synced_at')->nullable();
            $table->string('disabled_reason', 40)->nullable(); // directory_disabled | directory_deleted | admin
            $table->timestamp('disabled_at')->nullable();
        });

        Schema::create('directory_group', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 64);
            $table->string('object_id', 64);
            $table->string('display_name', 256)->nullable();
            $table->string('mail', 256)->nullable();
            $table->string('description', 1024)->nullable();
            $table->boolean('security_enabled')->nullable();
            $table->timestamp('removed_at')->nullable(); // removido/excluído no diretório
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'object_id']);
        });

        Schema::create('directory_membership', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('directory_group_id')->constrained('directory_group')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('source', 20)->default('graph');
            $table->timestamp('synced_at');
            $table->timestamp('removed_at')->nullable();
            $table->unique(['directory_group_id', 'user_id']);
        });

        // Regra explícita e revisável: grupo Entra → grupo/raia TMSA e/ou papéis. Sem regra = sem acesso.
        Schema::create('directory_role_mapping', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entra_group_id', 64);
            $table->string('entra_group_name', 256)->nullable();
            $table->string('tmsa_group_key', 40)->nullable();
            $table->jsonb('tmsa_roles')->default('[]');
            $table->boolean('privileged')->default(false);
            $table->boolean('enabled')->default(true);
            $table->string('justification', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->string('created_by_name', 200)->nullable();
            $table->timestamps();
            $table->index('entra_group_id');
        });

        Schema::create('directory_sync_run', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mode', 12); // full | delta | preview
            $table->string('trigger', 12); // manual | schedule | retry
            // queued | running | completed | partial | failed | blocked | cancelled
            $table->string('status', 12)->default('queued');
            $table->string('transport', 10)->default('graph'); // graph | mock
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->jsonb('totals')->default('{}');
            $table->jsonb('errors')->default('[]'); // por registro, sem token/PII
            $table->jsonb('plan')->nullable(); // prévia (dry run): mudanças planejadas, amostra limitada
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->boolean('cursor_advanced')->default(false);
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->string('requested_by_name', 200)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        // Checkpoint opaco por recurso. delta_link só é gravado após a rodada inteira; next_link apenas durante a paginação.
        Schema::create('directory_sync_cursor', function (Blueprint $table) {
            $table->id();
            $table->string('resource', 20)->unique(); // users | groups
            $table->text('delta_link')->nullable(); // criptografado (Crypt)
            $table->text('next_link')->nullable(); // criptografado; retomada após interrupção
            $table->uuid('next_link_run_id')->nullable();
            $table->string('scope_hash', 64)->nullable(); // seleção de grupos/campos codificada no token delta
            $table->timestamp('delta_saved_at')->nullable();
            $table->boolean('full_sync_required')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_sync_cursor');
        Schema::dropIfExists('directory_sync_run');
        Schema::dropIfExists('directory_role_mapping');
        Schema::dropIfExists('directory_membership');
        Schema::dropIfExists('directory_group');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['upn', 'department', 'user_type', 'account_enabled', 'token_roles', 'token_groups', 'directory_roles', 'directory_groups', 'directory_synced_at', 'disabled_reason', 'disabled_at']);
        });
    }
};
