<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Usuário espelhado do Entra ID. Chave de identidade = oid + tid (nunca e-mail).
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entra_oid', 64)->nullable();
            $table->string('entra_tid', 64)->nullable();
            $table->string('username', 120)->nullable()->unique(); // somente modo dev
            $table->string('name', 200);
            $table->string('email', 200)->nullable();
            $table->string('area', 200)->nullable();
            $table->jsonb('roles')->default('[]');
            $table->jsonb('groups')->default('[]'); // chaves de bpm_groups (raias)
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['entra_oid', 'entra_tid']);
        });

        // Grupos/raias lógicos (CQ, PPCPM, GEF...). Associação ao grupo Entra por ambiente.
        Schema::create('bpm_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 400)->nullable();
            $table->string('entra_group_id', 64)->nullable()->unique();
            $table->string('email', 200)->nullable(); // destino lógico de notificações
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpm_groups');
        Schema::dropIfExists('users');
    }
};
