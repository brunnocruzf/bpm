<?php

namespace Tests\Feature;

use App\Audit\AuditCatalog;
use App\Audit\AuditRetentionService;
use App\Flow\FlowBlueprint;
use App\Flow\PublishService;
use App\Forms\FormioBuilder as F;
use App\Models\AuditEvent;
use App\Models\EngineCommand;
use App\Models\ProcessStageVersion as S;
use App\Models\TaskProjection;
use App\Settings\SettingsRepository;
use App\Settings\Types\AuditRetentionSettings;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FakeFlowable;
use Tests\TestCase;
use Throwable;

class AuthorizationAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments_local');
        $this->seedIdentity();
    }

    public function test_settings_require_settings_manage_permission_on_the_server(): void
    {
        foreach (['cq', 'designer', 'auditor', 'gestor'] as $user) {
            $this->as($user)->getJson('/api/v1/admin/settings')->assertForbidden();
            $this->as($user)->putJson('/api/v1/admin/settings/branding', ['appName' => 'X'])->assertForbidden();
            $this->as($user)->postJson('/api/v1/admin/entra/sync/preview')->assertForbidden();
            $this->as($user)->getJson('/api/v1/admin/settings/email')->assertForbidden();
        }
        $this->as('admin')->getJson('/api/v1/admin/settings')->assertOk()->assertJsonCount(6, 'data');
        $this->as('admin')->getJson('/api/v1/me')->assertJsonPath('permissions.settingsManage', true);
        $this->as('cq')->getJson('/api/v1/me')->assertJsonPath('permissions.settingsManage', false)->assertJsonPath('permissions.reportsInstance', true);
    }

    public function test_audit_read_and_export_have_separate_permissions_and_no_write_routes(): void
    {
        AuditLogger::record('group.updated', 'bpm_group', 'x', ['fields' => ['email']]);
        $this->as('cq')->getJson('/api/v1/admin/audit-events')->assertForbidden();
        $this->as('designer')->getJson('/api/v1/admin/audit-events')->assertForbidden();
        $this->as('gestor')->getJson('/api/v1/admin/audit-events/export')->assertForbidden();
        $this->as('admin')->getJson('/api/v1/admin/audit-events')->assertOk();

        $id = AuditEvent::first()->id;
        $this->as('auditor')->putJson("/api/v1/admin/audit-events/$id", ['action' => 'x'])->assertStatus(405);
        $this->as('auditor')->deleteJson("/api/v1/admin/audit-events/$id")->assertStatus(405);

        $export = $this->as('auditor')->get('/api/v1/admin/audit-events/export?format=json');
        $export->assertOk();
        $rows = json_decode($export->streamedContent(), true);
        $this->assertNotEmpty($rows);
        $this->assertTrue(AuditEvent::where('action', 'audit.exported')->exists(), 'A exportação gera evento próprio');
    }

    public function test_audit_events_are_append_only_in_model_and_database(): void
    {
        $event = AuditLogger::record('group.updated', 'bpm_group', 'g1', []);
        try {
            $event->update(['action' => 'adulterado']);
            $this->fail('update deveria falhar');
        } catch (LogicException) {
        }
        foreach (['update' => fn () => DB::table('audit_event')->where('id', $event->id)->update(['action' => 'x']), 'delete' => fn () => DB::table('audit_event')->where('id', $event->id)->delete()] as $op => $fn) {
            try {
                $fn();
                $this->fail("$op direto no banco deveria ser bloqueado pelo trigger");
            } catch (Throwable $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame('group.updated', AuditEvent::find($event->id)->action);
    }

    public function test_redaction_never_stores_tokens_secrets_or_external_bodies(): void
    {
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJlLWZha2U';
        $event = AuditLogger::record('settings.updated', 'app_setting', 's', [
            'password' => 'P@ss', 'client_secret' => 'abc', 'note' => "Authorization: Bearer $jwt", 'pem' => "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----",
            'url' => 'https://graph.microsoft.com/v1.0/users/delta?$deltatoken=SEGREDO123',
        ], diff: ['smtp.passwordRef' => ['from' => 'db:1', 'to' => 'db:2']]);
        $raw = json_encode(AuditEvent::find($event->id)->toArray());
        foreach (['P@ss', 'abc"', $jwt, 'MIIE', 'SEGREDO123', 'db:2'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw);
        }
    }

    public function test_definition_changes_and_runtime_events_are_correlated_with_diff_and_causation(): void
    {
        $engine = FakeFlowable::install();
        $catalog = FlowBlueprint::create([
            'key' => 'compra', 'name' => 'Compra', 'category' => 'Compras', 'prefix' => 'CMP',
            'stages' => [
                ['key' => 'inicio', 'name' => 'Início', 'type' => S::START],
                ['key' => 'abertura', 'name' => 'Abertura', 'type' => S::USER_TASK, 'lane' => 'CQ', 'form' => ['components' => [F::text('item', 'Item', ['required' => true])]]],
                ['key' => 'analise', 'name' => 'Análise', 'type' => S::USER_TASK, 'lane' => 'GEF', 'form' => ['components' => [F::yesNo('ok', 'OK?', ['required' => true])]]],
                ['key' => 'fim', 'name' => 'Fim', 'type' => S::END],
            ],
            'transitions' => [['inicio', 'abertura'], ['abertura', 'analise'], ['analise', 'fim']],
        ], $this->dev('designer'));
        // alteração em rascunho gera diff
        $v = $catalog->latestVersion();
        $this->as('designer')->patchJson("/api/v1/definitions/compra/versions/{$v->version}/stages/analise", ['name' => 'Análise GEF'])->assertOk();
        $stageEvent = AuditEvent::where('action', 'stage.updated')->latest('occurred_at')->first();
        $this->assertSame(['from' => 'Análise', 'to' => 'Análise GEF'], $stageEvent->diff['name']);
        $this->assertSame('definition', $stageEvent->category);
        $this->assertSame('compra', $stageEvent->flow_key);

        app(PublishService::class)->publish($v->fresh(), $this->dev('publisher'));
        $this->drainOutbox();
        $engine->nextTasksOnStart = ['abertura'];
        $engine->nextTasksOnComplete = ['analise'];
        $this->as('cq')->withHeader('Idempotency-Key', 'k-'.Str::random(10))->postJson('/api/v1/process-instances', ['flowKey' => 'compra', 'data' => ['item' => 'Parafuso']])->assertStatus(202);
        $this->drainOutbox();

        $created = AuditEvent::where('action', 'instance.created')->first();
        $started = AuditEvent::where('action', 'engine.instance_started')->first();
        $this->assertSame($created->correlation_id, $started->correlation_id, 'worker herda o correlation ID da requisição');
        $this->assertSame($created->id, $started->causation_id, 'evento do engine aponta a causa (abertura)');
        $this->assertSame('system', $started->actor_type);
        $this->assertNotNull($started->outbox_id);
        $this->assertNotNull($started->engine_process_instance_id);
        $this->assertStringStartsWith('CMP-', (string) $started->instance_number);

        $task = TaskProjection::where('stage_key', 'analise')->first();
        $this->as('gef')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['ok' => 'sim']])->assertStatus(202);
        $completedEvent = AuditEvent::where('action', 'task.completed')->where('task_id', $task->id)->first();
        $this->assertSame('analise', $completedEvent->stage_key);
        $this->assertSame('POST /api/v1/tasks/{id}/complete', $completedEvent->operation);
        $this->assertSame($completedEvent->id, EngineCommand::where('task_projection_id', $task->id)->where('command', 'COMPLETE')->first()->causation_id);
        $this->drainOutbox();
        $this->assertTrue(AuditEvent::where('action', 'task.created')->where('stage_key', 'analise')->exists(), 'entrada na etapa registrada após confirmação do engine');

        // detalhe traz linha do tempo correlacionada
        $detail = $this->as('auditor')->getJson('/api/v1/admin/audit-events/'.$created->id)->assertOk();
        $this->assertContains('engine.instance_started', array_column($detail->json('related'), 'action'));

        // filtros
        $this->as('auditor')->getJson('/api/v1/admin/audit-events?category=engine&perPage=10')->assertOk()->assertJsonPath('data.0.category', 'engine');
        $this->as('auditor')->getJson('/api/v1/admin/audit-events?number='.$started->instance_number)->assertOk()->assertJsonPath('meta.total', fn ($n) => $n >= 4);
    }

    public function test_every_audit_action_used_in_code_is_in_the_typed_catalog(): void
    {
        $actions = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                preg_match_all("/AuditLogger::record\(\s*'([a-z_.]+)'/", (string) file_get_contents($file->getPathname()), $m);
                array_push($actions, ...$m[1]);
            }
        }
        $this->assertNotEmpty($actions);
        foreach (array_unique($actions) as $action) {
            $this->assertTrue(AuditCatalog::known($action), "Evento fora do catálogo: $action");
        }
    }

    public function test_retention_does_nothing_without_approved_period_and_archives_before_purging(): void
    {
        AuditEvent::create(['action' => 'group.updated', 'entity_type' => 'bpm_group', 'data' => [], 'occurred_at' => now()->subDays(400), 'category' => 'identity']);
        AuditEvent::create(['action' => 'group.updated', 'entity_type' => 'bpm_group', 'data' => [], 'occurred_at' => now()->subDays(5), 'category' => 'identity']);
        $service = app(AuditRetentionService::class);
        $this->assertSame(0, $service->run()['events'], 'sem prazo aprovado, nada é expurgado');

        $this->as('admin')->putJson('/api/v1/admin/settings/audit-retention', ['retentionDays' => 365])->assertStatus(422);
        $this->as('admin')->putJson('/api/v1/admin/settings/audit-retention', ['retentionDays' => 365, 'approvalReference' => 'JUR-2026-001', 'approvedBy' => 'Comitê de Privacidade'])->assertOk()->assertJsonPath('status', 'configured');
        app(SettingsRepository::class)->forget(app(AuditRetentionSettings::class));

        $result = $service->run();
        $this->assertSame(1, $result['events']);
        Storage::disk('attachments_local')->assertExists($result['archives'][0]);
        Storage::disk('attachments_local')->assertExists($result['archives'][0].'.manifest.json');
        $this->assertSame(0, AuditEvent::where('occurred_at', '<', now()->subDays(365))->count());
        $this->assertTrue(AuditEvent::where('action', 'audit.retention_purged')->exists());
    }
}
