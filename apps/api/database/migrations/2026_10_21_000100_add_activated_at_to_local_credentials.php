<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_credentials', function (Blueprint $table) {
            // momento em que o titular aceitou o convite e definiu a senha (distinto de "algum convite foi usado")
            $table->timestamp('activated_at')->nullable();
        });

        // Backfill em PHP (SQLite e PostgreSQL): evento de ativação por convite; sem evento (retenção),
        // uma senha trocada depois da criação da credencial indica que o titular a definiu.
        $rows = DB::table('local_credentials')->join('users', 'users.id', '=', 'local_credentials.user_id')
            ->where('users.auth_source', 'local')->where('users.is_active', true)
            ->get(['local_credentials.id as id', 'local_credentials.user_id as user_id', 'local_credentials.password_changed_at as changed', 'local_credentials.created_at as created']);
        foreach ($rows as $row) {
            $at = null;
            $events = DB::table('audit_event')->where('action', 'identity.local_user_activated')->where('entity_type', 'user')->where('entity_id', $row->user_id)->orderByDesc('occurred_at')->get(['data', 'occurred_at']);
            foreach ($events as $event) {
                $data = is_string($event->data) ? json_decode($event->data, true) : (array) $event->data;
                if (($data['method'] ?? null) === 'invitation') {
                    $at = $event->occurred_at;
                    break;
                }
            }
            if ($at === null && $row->changed && $row->created && strtotime((string) $row->changed) >= strtotime((string) $row->created) + 60) {
                $at = $row->changed;
            }
            if ($at !== null) {
                DB::table('local_credentials')->where('id', $row->id)->update(['activated_at' => $at]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('local_credentials', function (Blueprint $table) {
            $table->dropColumn('activated_at');
        });
    }
};
