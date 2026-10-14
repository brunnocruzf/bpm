<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuração organizacional tipada (organização TMSA única; sem multi-tenancy).
        // value guarda somente dados NÃO secretos; segredos ficam em secret_entry (criptografado) ou em cofre externo.
        Schema::create('app_setting', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 60)->unique(); // branding | entra.auth | entra.directory | email | audit.retention | reports
            $table->jsonb('value')->default('{}');
            $table->unsignedInteger('version')->default(1);
            // configured | incomplete | test_pending | error | disabled
            $table->string('status', 20)->default('incomplete');
            $table->string('status_detail', 500)->nullable();
            $table->jsonb('last_validation')->nullable(); // resultado sanitizado do último teste/validação
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->string('updated_by_name', 200)->nullable();
            $table->timestamps();
        });

        // Histórico de versões (valores já redigidos; nunca contém segredo).
        Schema::create('app_setting_revision', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 60);
            $table->unsignedInteger('version');
            $table->jsonb('value');
            $table->foreignUuid('changed_by')->nullable()->constrained('users');
            $table->string('changed_by_name', 200)->nullable();
            $table->timestamp('created_at');
            $table->unique(['setting_key', 'version']);
        });

        // Segredos criptografados com chave dedicada (BPM_SECRETS_KEY), separada da APP_KEY.
        // A API nunca devolve o conteúdo; o domínio guarda apenas a referência (db:<id> | file:<nome>).
        Schema::create('secret_entry', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('purpose', 80)->index(); // email.smtp.password | entra.directory.client_secret | ...
            $table->text('ciphertext');
            $table->string('key_id', 16); // identificador da chave usada (rotação)
            $table->string('fingerprint', 16); // prefixo de HMAC para detectar troca, sem revelar o valor
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });

        // Assets de identidade visual (logos/favicon) no storage adotado; referência versionada por hash.
        Schema::create('branding_asset', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 20); // logo_main | logo_compact | favicon
            $table->string('disk', 40);
            $table->string('object_key', 200)->unique();
            $table->string('content_type', 40);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('sha256', 64);
            $table->string('av_status', 20)->default('not_scanned');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_asset');
        Schema::dropIfExists('secret_entry');
        Schema::dropIfExists('app_setting_revision');
        Schema::dropIfExists('app_setting');
    }
};
