<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pedidos ao agente de backup (ADR-0021): tipos fixos, nunca a fila do Laravel. Campos validados, sem nada desserializável.
        Schema::create('backup_job', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 20);
            $table->string('status', 12)->default('pending'); // pending | running | succeeded | failed
            $table->json('params')->nullable();
            $table->text('sealed_secret')->nullable(); // chave de recuperação cifrada para o agente; apagada ao ser usada
            $table->string('schedule_id', 36)->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->string('backup_id', 40)->nullable();
            $table->string('stage', 40)->nullable();
            $table->unsignedTinyInteger('percent')->default(0);
            $table->string('error_code', 60)->nullable();
            $table->string('error_detail', 1000)->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name', 150)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            // um horário agendado roda uma vez só
            $table->unique(['schedule_id', 'scheduled_for']);
            $table->index(['status', 'created_at']);
        });

        // Catálogo: cache dos manifestos. A fonte da verdade é o manifest.json de cada backup, no disco e no Azure.
        Schema::create('backup_catalog', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('scope', 12);
            $table->string('origin', 12); // manual | schedule | pre_restore
            $table->string('schedule_id', 36)->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->boolean('in_local')->default(false);
            $table->boolean('in_azure')->default(false);
            $table->json('manifest');
            $table->char('manifest_sha256', 64);
            $table->string('key_fingerprint', 20);
            $table->timestamp('backup_at');
            $table->timestamp('keep_until')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('verification')->nullable();
            $table->timestamps();
            $table->index('backup_at');
            $table->index('schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_catalog');
        Schema::dropIfExists('backup_job');
    }
};
