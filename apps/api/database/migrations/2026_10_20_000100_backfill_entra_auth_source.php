<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotNull('entra_oid')->update(['auth_source' => 'entra']);
    }

    public function down(): void
    {
        // Entra identities must retain their source; do not erase data on rollback.
    }
};
