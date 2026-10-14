<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only na aplicação (o model bloqueia update/delete).
        Schema::create('audit_event', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('users');
            $table->string('actor_name', 200)->nullable();
            $table->string('action', 80);
            $table->string('entity_type', 60);
            $table->string('entity_id', 64)->nullable();
            $table->uuid('process_instance_id')->nullable()->index();
            $table->string('correlation_id', 64)->nullable();
            $table->jsonb('data')->default('{}');
            $table->timestamp('occurred_at');
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('engine_command_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('command', 20); // DEPLOY | START | COMPLETE
            $table->string('idempotency_key', 160)->unique();
            $table->uuid('definition_version_id')->nullable()->index();
            $table->uuid('process_instance_id')->nullable()->index();
            $table->uuid('task_projection_id')->nullable()->index();
            $table->jsonb('payload')->default('{}');
            // PENDING | SENDING | CONFIRMED | RECONCILIATION_REQUIRED | FAILED
            $table->string('state', 30)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('correlation_id', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('response')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'available_at']);
        });

        Schema::create('sequence_counter', function (Blueprint $table) {
            $table->id();
            $table->string('sequence_key', 80);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('value')->default(0);
            $table->timestamps();
            $table->unique(['sequence_key', 'year']);
        });

        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event', 60);
            $table->string('channel', 20)->default('mail');
            $table->string('recipient', 120); // destino lógico: group:PPCPM | user:<uuid>
            $table->string('recipient_address', 200)->nullable();
            $table->string('subject', 200);
            $table->text('body');
            $table->string('state', 20)->default('PENDING'); // PENDING | SENT | FAILED | SKIPPED
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->uuid('process_instance_id')->nullable()->index();
            $table->timestamp('available_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_execution', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('system', 30);
            $table->string('operation', 60);
            $table->string('transport', 30);
            $table->string('idempotency_key', 160)->nullable()->index();
            $table->jsonb('request_masked')->nullable();
            $table->jsonb('response_masked')->nullable();
            $table->string('result', 20); // success | not_found | error | timeout | not_configured
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('correlation_id', 64)->nullable();
            $table->uuid('process_instance_id')->nullable()->index();
            $table->timestamp('created_at');
        });

        Schema::create('idempotency_record', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 160);
            $table->uuid('user_id');
            $table->string('scope', 80);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->jsonb('response_body');
            $table->timestamp('created_at');
            $table->unique(['idempotency_key', 'user_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_record');
        Schema::dropIfExists('integration_execution');
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('sequence_counter');
        Schema::dropIfExists('engine_command_outbox');
        Schema::dropIfExists('audit_event');
    }
};
