<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modelos versionados. Versão ativa é imutável; alterar = nova versão (mesma regra das definições BPM).
        Schema::create('report_template', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 60);
            $table->unsignedInteger('version');
            $table->string('name', 160);
            $table->string('kind', 20); // instance | operational
            $table->string('engine', 20)->default('native'); // native | carbone
            $table->string('status', 20)->default('draft'); // draft | active | retired
            $table->jsonb('options')->default('{}'); // título, cabeçalho, confidencialidade, seções
            // Arquivo de modelo (Carbone: DOCX/ODT/XLSX) em storage privado; nunca executável.
            $table->string('file_disk', 40)->nullable();
            $table->string('file_key', 200)->nullable();
            $table->string('file_sha256', 64)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->string('created_by_name', 200)->nullable();
            $table->foreignUuid('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['key', 'version']);
        });

        Schema::create('report_job', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('report_type', 40); // instance | instances | stage_aging | pending | sla | integration_errors | notifications
            $table->foreignUuid('template_id')->nullable()->constrained('report_template');
            $table->unsignedInteger('template_version')->nullable();
            $table->uuid('process_instance_id')->nullable()->index();
            $table->jsonb('parameters')->default('{}'); // escopo/filtros validados (sem dados pessoais livres)
            $table->string('format', 10); // pdf | csv | xlsx
            // queued | rendering | ready | failed | expired
            $table->string('status', 12)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('renderer', 20)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('artifact_disk', 40)->nullable();
            $table->string('artifact_key', 200)->nullable();
            $table->string('artifact_name', 200)->nullable();
            $table->string('artifact_type', 100)->nullable();
            $table->unsignedInteger('artifact_size')->nullable();
            $table->string('artifact_sha256', 64)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->string('requested_by_name', 200);
            $table->string('idempotency_key', 160)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['requested_by', 'idempotency_key']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('engine_command_outbox', function (Blueprint $table) {
            $table->string('causation_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('engine_command_outbox', function (Blueprint $table) {
            $table->dropColumn('causation_id');
        });
        Schema::dropIfExists('report_job');
        Schema::dropIfExists('report_template');
    }
};
