<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_instance', function (Blueprint $table) {
            $table->uuid('id')->primary(); // também é o businessKey no Flowable
            $table->foreignUuid('catalog_id')->constrained('process_catalog');
            $table->foreignUuid('definition_version_id')->constrained('process_definition_version');
            $table->string('number', 40)->unique();
            $table->unsignedSmallInteger('sequence_year');
            $table->unsignedInteger('sequence_value');
            $table->string('engine_process_instance_id', 64)->nullable()->unique();
            $table->foreignUuid('requester_id')->constrained('users');
            $table->string('requester_name', 200); // snapshot
            $table->string('requester_area', 200)->nullable(); // snapshot
            // starting | running | completed | cancelled | error
            $table->string('status', 20)->default('starting');
            $table->string('result', 40)->nullable(); // concluido | nao_procedente | ...
            $table->string('end_stage_key', 64)->nullable();
            $table->jsonb('data')->default('{}'); // contexto de negócio (snapshot das submissões)
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['catalog_id', 'status']);
        });

        Schema::create('task_projection', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('process_instance_id')->constrained('process_instance')->cascadeOnDelete();
            $table->string('engine_task_id', 64)->nullable()->unique();
            $table->foreignUuid('stage_version_id')->constrained('process_stage_version');
            $table->string('stage_key', 64);
            $table->string('name', 160);
            $table->string('candidate_group', 40)->nullable();
            $table->foreignUuid('assignee_id')->nullable()->constrained('users');
            // open | claimed | completing | completed | cancelled
            $table->string('status', 20)->default('open');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('engine_created_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUuid('completed_by')->nullable()->constrained('users');
            $table->unsignedInteger('row_version')->default(1); // ETag/concorrência otimista
            $table->timestamps();
            $table->index(['status', 'candidate_group']);
            $table->index(['assignee_id', 'status']);
        });

        Schema::create('form_submission', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('process_instance_id')->constrained('process_instance')->cascadeOnDelete();
            $table->foreignUuid('task_projection_id')->nullable()->constrained('task_projection');
            $table->foreignUuid('stage_version_id')->constrained('process_stage_version');
            $table->foreignUuid('form_schema_version_id')->constrained('form_schema_version');
            $table->string('form_schema_hash', 64)->nullable();
            $table->jsonb('payload');
            $table->jsonb('metadata')->default('{}'); // lookups (origem/timestamp), campos automáticos
            $table->foreignUuid('author_id')->constrained('users');
            $table->string('author_name', 200);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('created_at');
            $table->index(['process_instance_id', 'stage_version_id']);
        });

        Schema::create('task_draft', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_projection_id')->constrained('task_projection')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users');
            $table->jsonb('payload');
            $table->timestamps();
            $table->unique(['task_projection_id', 'user_id']);
        });

        Schema::create('supplier_action', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('process_instance_id')->constrained('process_instance')->cascadeOnDelete();
            $table->foreignUuid('source_submission_id')->constrained('form_submission');
            $table->string('kind', 20)->default('corretiva'); // corretiva | preventiva
            $table->text('action_text');
            $table->string('responsible', 200);
            $table->date('due_date')->nullable();
            $table->string('location', 200)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending'); // pending | done | superseded
            $table->unsignedInteger('position')->default(0);
            $table->foreignUuid('completed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('attachment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('disk', 40);
            $table->string('object_key', 300)->unique();
            $table->string('original_name', 255);
            $table->string('content_type', 120);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->foreignUuid('uploader_id')->constrained('users');
            $table->foreignUuid('process_instance_id')->nullable()->constrained('process_instance');
            $table->foreignUuid('task_projection_id')->nullable()->constrained('task_projection');
            $table->foreignUuid('supplier_action_id')->nullable()->constrained('supplier_action');
            $table->string('field_key', 64)->nullable();
            $table->string('av_status', 20)->default('pending'); // pending | clean | infected | not_scanned
            $table->date('retention_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment');
        Schema::dropIfExists('supplier_action');
        Schema::dropIfExists('task_draft');
        Schema::dropIfExists('form_submission');
        Schema::dropIfExists('task_projection');
        Schema::dropIfExists('process_instance');
    }
};
