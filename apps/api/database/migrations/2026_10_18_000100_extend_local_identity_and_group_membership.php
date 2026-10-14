<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('manual_groups')->default('[]');
        });
        Schema::create('local_identity_token', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('purpose', 20); // invitation | password_reset
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'used_at']);
        });

        Schema::create('bpm_group_membership', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('group_id')->constrained('bpm_groups')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('source', 20)->default('manual');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->string('created_by_name', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['group_id', 'user_id', 'source']);
            $table->index(['user_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bpm_group_membership');
        Schema::dropIfExists('local_identity_token');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('manual_groups'));
    }
};
