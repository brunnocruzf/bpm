<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Miniatura da foto do Entra ID por usuário (bytes no disco privado; aqui só metadados).
        Schema::create('user_photo', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('disk', 40)->nullable();
            $table->string('object_key', 200)->nullable();
            $table->string('content_type', 40)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('graph_etag', 100)->nullable();
            $table->string('status', 12)->default('absent'); // present | absent | error
            $table->string('error_code', 40)->nullable();
            $table->timestamp('checked_at')->nullable()->index();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_photo');
    }
};
