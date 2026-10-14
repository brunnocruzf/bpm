<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de auditoria funcional (ADR-0010). Colunas novas são anuláveis/sem reescrita de tabela;
 * índices em PostgreSQL são criados com CONCURRENTLY (migração fora de transação) para não travar
 * a tabela em produção. Append-only reforçado por trigger: UPDATE/TRUNCATE sempre recusados;
 * DELETE apenas pelo expurgo de retenção (bpm:audit-retention), que abre o guarda na própria transação.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('audit_event', function (Blueprint $table) {
            $table->string('organization_key', 40)->nullable();
            $table->string('category', 30)->nullable();
            $table->string('outcome', 12)->nullable(); // success | failure | blocked
            $table->string('actor_type', 20)->nullable(); // user | system
            $table->string('actor_oid', 64)->nullable();
            $table->string('actor_upn', 200)->nullable();
            $table->string('object_name', 200)->nullable();
            $table->string('flow_key', 80)->nullable();
            $table->unsignedInteger('definition_version')->nullable();
            $table->string('engine_process_instance_id', 64)->nullable();
            $table->string('instance_number', 40)->nullable();
            $table->string('stage_key', 64)->nullable();
            $table->uuid('task_id')->nullable();
            $table->uuid('form_schema_version_id')->nullable();
            $table->string('causation_id', 64)->nullable();
            $table->string('idempotency_key', 160)->nullable();
            $table->uuid('outbox_id')->nullable();
            $table->string('operation', 160)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 300)->nullable();
            $table->jsonb('diff')->nullable();
        });

        Schema::create('audit_purge_guard', function (Blueprint $table) {
            $table->uuid('token')->primary();
        });

        $indexes = [
            'audit_event_occurred_at_idx' => '(occurred_at)',
            'audit_event_category_idx' => '(category, occurred_at)',
            'audit_event_correlation_idx' => '(correlation_id)',
            'audit_event_actor_idx' => '(actor_id, occurred_at)',
            'audit_event_number_idx' => '(instance_number)',
            'audit_event_action_idx' => '(action, occurred_at)',
        ];

        if (DB::getDriverName() === 'pgsql') {
            foreach ($indexes as $name => $cols) {
                DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS $name ON audit_event $cols");
            }
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_event_append_only() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' AND EXISTS (SELECT 1 FROM audit_purge_guard) THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'audit_event é append-only (operação % recusada)', TG_OP USING ERRCODE = '42501';
                END
                $$ LANGUAGE plpgsql;
                DROP TRIGGER IF EXISTS audit_event_append_only ON audit_event;
                CREATE TRIGGER audit_event_append_only BEFORE UPDATE OR DELETE ON audit_event
                    FOR EACH ROW EXECUTE FUNCTION audit_event_append_only();
                DROP TRIGGER IF EXISTS audit_event_no_truncate ON audit_event;
                CREATE TRIGGER audit_event_no_truncate BEFORE TRUNCATE ON audit_event
                    FOR EACH STATEMENT EXECUTE FUNCTION audit_event_append_only();
                SQL);
        } else {
            foreach ($indexes as $name => $cols) {
                DB::statement("CREATE INDEX IF NOT EXISTS $name ON audit_event $cols");
            }
            DB::unprepared("CREATE TRIGGER audit_event_no_update BEFORE UPDATE ON audit_event BEGIN SELECT RAISE(ABORT, 'audit_event é append-only'); END;");
            DB::unprepared("CREATE TRIGGER audit_event_no_delete BEFORE DELETE ON audit_event WHEN NOT EXISTS (SELECT 1 FROM audit_purge_guard) BEGIN SELECT RAISE(ABORT, 'audit_event é append-only'); END;");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_event_append_only ON audit_event; DROP TRIGGER IF EXISTS audit_event_no_truncate ON audit_event; DROP FUNCTION IF EXISTS audit_event_append_only();');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_event_no_update; DROP TRIGGER IF EXISTS audit_event_no_delete;');
        }
        foreach (['audit_event_occurred_at_idx', 'audit_event_category_idx', 'audit_event_correlation_idx', 'audit_event_actor_idx', 'audit_event_number_idx', 'audit_event_action_idx'] as $name) {
            DB::statement("DROP INDEX IF EXISTS $name");
        }
        Schema::dropIfExists('audit_purge_guard');
        Schema::table('audit_event', function (Blueprint $table) {
            $table->dropColumn([
                'organization_key', 'category', 'outcome', 'actor_type', 'actor_oid', 'actor_upn', 'object_name', 'flow_key',
                'definition_version', 'engine_process_instance_id', 'instance_number', 'stage_key', 'task_id', 'form_schema_version_id',
                'causation_id', 'idempotency_key', 'outbox_id', 'operation', 'ip_address', 'user_agent', 'diff',
            ]);
        });
    }
};
