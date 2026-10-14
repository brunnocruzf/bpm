<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_catalog', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 64)->unique(); // id do processo BPMN (NCName)
            $table->string('name', 120);
            $table->string('category', 60);
            $table->string('description', 400)->nullable();
            $table->string('status', 20)->default('active'); // active | suspended | retired
            $table->string('instance_prefix', 10);
            $table->jsonb('initiator_groups')->nullable(); // null = qualquer Bpm.Initiator
            $table->uuid('current_published_version_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('process_definition_version', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('catalog_id')->constrained('process_catalog')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft'); // draft | published | suspended | retired
            $table->longText('bpmn_xml')->nullable(); // congelado na publicação
            $table->jsonb('settings')->default('{}');
            $table->string('hash', 64)->nullable();
            $table->jsonb('validation_result')->nullable();
            $table->string('engine_deployment_id', 64)->nullable();
            $table->string('engine_definition_id', 128)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->foreignUuid('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['catalog_id', 'version']);
        });

        Schema::create('process_stage_version', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('definition_version_id')->constrained('process_definition_version')->cascadeOnDelete();
            $table->string('stage_key', 64);
            $table->string('element_id', 64);
            $table->string('name', 120);
            // start | user_task | exclusive_gateway | parallel_gateway | service_task | timer | end
            $table->string('stage_type', 30);
            $table->string('lane', 40)->nullable(); // chave de bpm_groups
            $table->unsignedInteger('position')->default(0);
            $table->text('description')->nullable();
            $table->jsonb('config')->default('{}');
            $table->uuid('form_schema_version_id')->nullable();
            $table->timestamps();
            $table->unique(['definition_version_id', 'stage_key']);
            $table->unique(['definition_version_id', 'element_id']);
        });

        Schema::create('process_transition_version', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('definition_version_id')->constrained('process_definition_version')->cascadeOnDelete();
            $table->string('element_id', 64);
            $table->foreignUuid('source_stage_id')->constrained('process_stage_version')->cascadeOnDelete();
            $table->foreignUuid('target_stage_id')->constrained('process_stage_version')->cascadeOnDelete();
            $table->string('label', 120)->nullable();
            $table->jsonb('condition')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['definition_version_id', 'element_id']);
        });

        Schema::create('form_schema_version', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('definition_version_id')->constrained('process_definition_version')->cascadeOnDelete();
            $table->foreignUuid('stage_version_id')->unique()->constrained('process_stage_version')->cascadeOnDelete();
            $table->string('form_key', 64);
            $table->string('title', 160);
            $table->jsonb('schema');
            $table->string('renderer_version', 20)->default('formio-5');
            $table->string('hash', 64)->nullable();
            $table->string('status', 20)->default('draft'); // draft | published
            $table->timestamps();
            $table->unique(['definition_version_id', 'form_key']);
        });

        Schema::table('process_stage_version', function (Blueprint $table) {
            $table->foreign('form_schema_version_id')->references('id')->on('form_schema_version')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('process_stage_version', function (Blueprint $table) {
            $table->dropForeign(['form_schema_version_id']);
        });
        Schema::dropIfExists('form_schema_version');
        Schema::dropIfExists('process_transition_version');
        Schema::dropIfExists('process_stage_version');
        Schema::dropIfExists('process_definition_version');
        Schema::dropIfExists('process_catalog');
    }
};
