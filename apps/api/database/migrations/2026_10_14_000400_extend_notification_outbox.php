<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox de e-mail: queued → sending → sent | retrying | failed (dead-letter) | skipped.
 * Idempotência por instância + tarefa + destinatário + tipo; tentativas e erro sanitizado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->string('idempotency_key', 200)->nullable()->unique();
            $table->uuid('task_projection_id')->nullable()->index();
            $table->uuid('recipient_user_id')->nullable();
            $table->string('recipient_name', 200)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('causation_id', 64)->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('provider_message_id', 200)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(6);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->index(['state', 'available_at']);
        });

        foreach (['PENDING' => 'queued', 'SENT' => 'sent', 'FAILED' => 'failed', 'SKIPPED' => 'skipped'] as $old => $new) {
            DB::table('notification_outbox')->where('state', $old)->update(['state' => $new]);
        }
    }

    public function down(): void
    {
        foreach (['queued' => 'PENDING', 'retrying' => 'PENDING', 'sending' => 'PENDING', 'sent' => 'SENT', 'failed' => 'FAILED', 'skipped' => 'SKIPPED'] as $new => $old) {
            DB::table('notification_outbox')->where('state', $new)->update(['state' => $old]);
        }
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->dropIndex(['state', 'available_at']);
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['task_projection_id']);
            $table->dropColumn(['idempotency_key', 'task_projection_id', 'recipient_user_id', 'recipient_name', 'correlation_id', 'causation_id', 'provider', 'provider_message_id', 'error_code', 'max_attempts', 'locked_until', 'last_attempt_at', 'sent_at', 'failed_at']);
        });
    }
};
