<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Destinos de armazenamento de anexos já ativados. O nome do disco (attachment.disk) aponta para uma linha daqui,
        // então anexos antigos continuam legíveis quando a configuração muda. O segredo é uma cópia própria (credential_ref).
        Schema::create('storage_target', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('disk', 40)->unique();
            $table->string('provider', 20);
            $table->json('config');
            $table->string('credential_ref', 120)->nullable();
            $table->string('status', 12)->default('active'); // active | retired
            $table->unsignedInteger('setting_version')->nullable();
            $table->foreignUuid('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_target');
    }
};
