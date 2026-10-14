<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Métodos de login simultâneos (ADR-0015): origem da conta (entra | demo | superadmin),
 * credencial local do SuperAdmin (somente hash) e sessões opacas emitidas pelo Laravel (somente hash do token).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_source', 20)->nullable()->index();
        });
        // contas existentes: espelhadas do Entra (oid) ou usuários fictícios de demonstração (username)
        DB::table('users')->whereNotNull('entra_oid')->update(['auth_source' => 'entra']);
        DB::table('users')->whereNull('entra_oid')->whereNotNull('username')->update(['auth_source' => 'demo']);

        Schema::create('local_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('password_hash', 255);
            $table->timestamp('password_changed_at');
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('method', 20);
            $table->char('token_hash', 64)->unique(); // sha256 do token opaco; o token nunca é gravado
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('last_used_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 40)->nullable();
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
        Schema::dropIfExists('local_credentials');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('auth_source'));
    }
};
