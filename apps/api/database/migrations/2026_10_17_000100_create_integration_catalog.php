<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de integrações REST (ADR-0016), inspirado nas "fontes de dados REST" do SoftExpert:
 * conexão (URL base, autenticação, credencial no cofre, documento OpenAPI) → endpoints (método,
 * caminho com {parâmetros}, parâmetros de entrada, corpo, campos de saída) → vínculos com as
 * operações do BPM (consultas dos formulários e tarefas de integração).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connection', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name', 120);
            $table->string('system', 40);
            $table->string('base_url', 300);
            $table->string('environment', 20)->default('homologacao');
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('timeout_seconds')->default(15);
            $table->string('auth_type', 20)->default('none');
            $table->json('auth_config')->nullable();
            $table->string('secret_ref', 200)->nullable();
            $table->string('openapi_url', 300)->nullable();
            $table->string('openapi_hash', 64)->nullable();
            $table->string('openapi_title', 200)->nullable();
            $table->string('openapi_version', 60)->nullable();
            $table->timestamp('openapi_imported_at')->nullable();
            $table->timestamp('openapi_checked_at')->nullable();
            $table->boolean('allow_writes')->default(false);
            $table->string('writes_approval', 200)->nullable();
            $table->json('last_test')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_endpoint', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained('integration_connection')->cascadeOnDelete();
            $table->string('key', 80)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('tag', 80)->nullable();
            $table->string('method', 10);
            $table->string('path', 300);
            $table->string('operation_id', 120)->nullable();
            $table->boolean('enabled')->default(false);
            $table->json('params')->nullable();          // [{name,in,type,required,mode,constant,description}]
            $table->json('body_fields')->nullable();     // campos do corpo (do OpenAPI)
            $table->json('response_fields')->nullable(); // [{path,label,type,sensitive}]
            $table->json('response_sample')->nullable(); // exemplo já mascarado
            $table->boolean('response_is_list')->default(false);
            $table->string('result_path', 120)->nullable();
            $table->json('lookup')->nullable();          // {expose,label,inputParam,fields[]}
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->string('source', 10)->default('manual'); // swagger | manual
            $table->string('contract_hash', 64)->nullable();
            $table->string('contract_status', 20)->default('ok'); // ok | changed | removed
            $table->json('warnings')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'method', 'path']);
        });

        Schema::create('integration_binding', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('operation', 60)->unique();
            $table->foreignUuid('endpoint_id')->nullable()->constrained('integration_endpoint')->nullOnDelete();
            $table->boolean('enabled')->default(false);
            $table->json('input_map')->nullable();  // destino no endpoint => origem ("input:<campo>" | "const:<valor>")
            $table->json('output_map')->nullable(); // campo da operação => caminho na resposta
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::table('integration_execution', function (Blueprint $table) {
            $table->string('system', 40)->change();
            $table->string('operation', 120)->change();
            $table->string('transport', 60)->change();
            $table->string('result', 40)->change();
            $table->string('endpoint_key', 80)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('result_data')->nullable(); // saídas mapeadas (idempotência de escritas)
            $table->index(['operation', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('integration_execution', function (Blueprint $table) {
            $table->dropIndex(['operation', 'created_at']);
            $table->dropColumn(['endpoint_key', 'http_status', 'result_data']);
        });
        Schema::dropIfExists('integration_binding');
        Schema::dropIfExists('integration_endpoint');
        Schema::dropIfExists('integration_connection');
    }
};
