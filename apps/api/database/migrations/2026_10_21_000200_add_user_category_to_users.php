<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // supplier | customer | internal — classificação administrativa; user_type continua sendo o Member/Guest do Entra
            $table->string('user_category', 20)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['user_category']);
            $table->dropColumn('user_category');
        });
    }
};
