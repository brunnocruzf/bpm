<?php

namespace Tests\Feature;

use App\Identity\DirectorySyncService;
use App\Mail\MailException;
use App\Models\AuditEvent;
use App\Models\DirectorySyncRun;
use App\Models\NotificationMessage;
use App\Models\SettingValidation;
use App\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGraph;
use Tests\Support\FakeMail;
use Tests\TestCase;

/** Logs de integração em Configurações: e-mail e notificações · Entra ID (login + importação). */
class IntegrationLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
    }

    private function saveSmtp(): void
    {
        $this->as('admin')->putJson('/api/v1/admin/settings/email', [
            'provider' => 'smtp', 'fromAddress' => 'bpm@tmsa.example', 'fromName' => 'BPM',
            'smtp' => ['host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'starttls', 'auth' => true, 'username' => 'bpm@tmsa.example', 'password' => 'Senha-Do-Smtp-1'],
        ])->assertOk();
    }

    public function test_email_log_keeps_every_test_and_each_delivery_attempt_with_explanations(): void
    {
        $mail = FakeMail::install();
        $this->saveSmtp();

        // dois testes de conexão: o histórico guarda ambos (antes só ficava o último)
        $mail->testChecks = [['name' => 'Conexão, TLS e autenticação SMTP', 'ok' => false, 'code' => 'auth_failed', 'message' => 'Servidor SMTP recusou a autenticação.', 'durationMs' => 40]];
        $this->as('admin')->postJson('/api/v1/admin/settings/email/test-connection')->assertOk();
        $mail->testChecks = [['name' => 'Conexão, TLS e autenticação SMTP', 'ok' => true, 'code' => null, 'message' => null, 'durationMs' => 35]];
        $this->as('admin')->postJson('/api/v1/admin/settings/email/test-connection')->assertOk();
        $this->assertSame(2, SettingValidation::where('setting_key', 'email')->count());

        // entrega com falha transitória → nova tentativa → enviada
        $mail->failures = [new MailException('timeout', 'Tempo esgotado.', true)];
        app(Notifier::class)->toGroup('GEF', 'stage.completed', 'Etapa concluída IRR-000001/26', 'Corpo', null, 'k:log:1');
        $this->drainNotifications();
        $this->drainNotifications();
        $msg = NotificationMessage::where('idempotency_key', 'k:log:1')->firstOrFail();
        $this->assertSame(NotificationMessage::SENT, $msg->state);
        $this->assertSame(['retry', 'sent'], array_column($msg->attempt_log ?? [], 'outcome'));

        $res = $this->as('admin')->getJson('/api/v1/admin/settings/logs/email')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $kinds = collect($res->json('data'))->pluck('kind')->unique()->values()->all();
        foreach (['test', 'delivery', 'config'] as $k) {
            $this->assertContains($k, $kinds);
        }
        $delivery = collect($res->json('data'))->firstWhere('kind', 'delivery');
        $this->assertSame('success', $delivery['level']);
        $this->assertCount(2, $delivery['steps']);
        $this->assertSame('timeout', $delivery['steps'][0]['code']);
        $this->assertSame('Tempo esgotado', $delivery['steps'][0]['explain']['title']);
        $this->assertStringContainsString('Etapa concluída IRR-000001/26', $delivery['summary']);
        $failedTest = collect($res->json('data'))->first(fn ($e) => $e['kind'] === 'test' && $e['level'] === 'error');
        $this->assertSame('auth_failed', $failedTest['code']);
        $this->assertSame('Autenticação recusada', $failedTest['explain']['title']);
        $this->assertSame(40, $failedTest['steps'][0]['durationMs']);
        $this->assertSame(1, $res->json('stats.testsFailed'));
        $this->assertSame(1, $res->json('stats.deliveries.sent'));

        // filtros: somente falhas / tipo / busca
        $only = $this->as('admin')->getJson('/api/v1/admin/settings/logs/email?failures=1')->json('data');
        $this->assertTrue(collect($only)->every(fn ($e) => in_array($e['level'], ['error', 'warning'], true)));
        $this->assertSame(['delivery'], collect($this->as('admin')->getJson('/api/v1/admin/settings/logs/email?kind=delivery')->json('data'))->pluck('kind')->unique()->values()->all());
        $this->assertCount(1, $this->as('admin')->getJson('/api/v1/admin/settings/logs/email?q=IRR-000001')->json('data'));

        // nada sensível no log: senha SMTP nem corpo da mensagem
        $all = json_encode($this->as('admin')->getJson('/api/v1/admin/settings/logs/email?limit=100')->json());
        $this->assertStringNotContainsString('Senha-Do-Smtp-1', (string) $all);
        $this->assertStringNotContainsString('"body"', (string) $all);
    }

    public function test_email_log_paginates_across_sources_without_losing_or_repeating_entries(): void
    {
        FakeMail::install();
        $this->saveSmtp();
        for ($i = 1; $i <= 12; $i++) {
            app(Notifier::class)->toGroup('GEF', 'stage.completed', "Aviso $i", 'Corpo', null, "k:page:$i");
        }
        $seen = [];
        $cursor = null;
        do {
            $res = $this->as('admin')->getJson('/api/v1/admin/settings/logs/email?limit=5'.($cursor ? '&cursor='.urlencode($cursor) : ''))->assertOk();
            foreach ($res->json('data') as $e) {
                $seen[] = $e['id'];
            }
            $cursor = $res->json('next');
        } while ($cursor);
        $this->assertSame(count($seen), count(array_unique($seen)), 'sem repetição');
        $this->assertSame(12 + 2, count($seen), '12 entregas + configuração salva + segredo substituído');
    }

    public function test_entra_log_shows_sync_steps_rejected_tokens_and_respects_permissions(): void
    {
        Sleep::fake();
        $graph = FakeGraph::install();
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', [
            'enabled' => true, 'transport' => 'graph', 'tenantId' => FakeGraph::TENANT, 'clientId' => 'cccccccc-0000-4000-8000-000000000001',
            'credentialType' => 'client_secret', 'clientSecret' => 'S3gredo-Do-App', 'clientSecretApproval' => 'SEC-2026-042',
            'groups' => [['id' => FakeGraph::G1, 'name' => 'BPM-CQ']],
        ])->assertOk();
        $graph->members[FakeGraph::G1] = [$graph->user('1'), $graph->user('2', ['userType' => 'Guest'])];
        $id = $this->as('admin')->postJson('/api/v1/admin/entra/sync/run', ['mode' => 'delta'])->assertStatus(202)->json('id');
        app(DirectorySyncService::class)->execute($id);
        $run = DirectorySyncRun::findOrFail($id);
        $steps = array_column($run->log ?? [], 'step');
        foreach (['start', 'mode', 'groups_page', 'groups_done', 'users_done', 'memberships', 'roles', 'checkpoint', 'finish'] as $s) {
            $this->assertContains($s, $steps, "etapa $s registrada");
        }

        // falha de consentimento: código + explicação no log da execução
        $graph->failures['groups/delta'] = [['status' => 403, 'body' => ['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges']]]];
        $id2 = $this->as('admin')->postJson('/api/v1/admin/entra/sync/run', ['mode' => 'delta'])->assertStatus(202)->json('id');
        app(DirectorySyncService::class)->execute($id2);

        // token Entra recusado pela API (config Entra do ambiente) → auditado uma vez, sem o token
        config(['bpm.auth.entra.tenant_id' => '11111111-2222-3333-4444-555555555555', 'bpm.auth.entra.audiences' => ['api://bpm'], 'bpm.auth.entra.allowed_tenants' => ['11111111-2222-3333-4444-555555555555']]);
        $fake = 'eyJhbGciOiJSUzI1NiJ9.'.rtrim(strtr(base64_encode((string) json_encode(['aud' => 'api://outra-api', 'tid' => 'tid-x'])), '+/', '-_'), '=').'.assinatura';
        for ($i = 0; $i < 3; $i++) {
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            $this->withHeader('Authorization', 'Bearer '.$fake)->getJson('/api/v1/me')->assertUnauthorized();
        }
        $this->assertSame(1, AuditEvent::where('action', 'auth.token_rejected')->count(), 'deduplicado por token');
        $this->assertStringNotContainsString('assinatura', json_encode(AuditEvent::where('action', 'auth.token_rejected')->first()?->toArray()));

        $res = $this->as('admin')->getJson('/api/v1/admin/settings/logs/entra')->assertOk();
        $data = collect($res->json('data'));
        $failed = $data->first(fn ($e) => $e['kind'] === 'sync' && $e['level'] === 'error');
        $this->assertSame('consent_missing', $failed['code']);
        $this->assertSame('Permissão de APLICAÇÃO ausente (Delegated não serve)', $failed['explain']['title'], 'explicação específica da importação');
        $this->assertNotEmpty(collect($failed['steps'])->firstWhere('level', 'error'));
        $ok = $data->first(fn ($e) => $e['kind'] === 'sync' && $e['level'] === 'success');
        $this->assertGreaterThan(5, count($ok['steps']));
        $rejected = $data->firstWhere('kind', 'access');
        $this->assertSame('auth.token_rejected', AuditEvent::where('action', 'auth.token_rejected')->value('action'));
        $this->assertContains($rejected['code'], ['jwks_unavailable', 'token_malformed', 'signature_invalid'], 'motivo estável e explicado');
        $this->assertNotNull($rejected['explain']);
        $this->assertContains('AUD (não verificado)', array_column($rejected['facts'], 'label'));
        $this->assertSame(1, $res->json('stats.rejectedTokens'));
        $this->assertGreaterThanOrEqual(1, $res->json('stats.runs.failed'));

        // filtros de origem: login × importação
        $login = collect($this->as('admin')->getJson('/api/v1/admin/settings/logs/entra?subsystem=login')->json('data'));
        $this->assertTrue($login->every(fn ($e) => $e['subsystem'] === 'login'));
        $dir = collect($this->as('admin')->getJson('/api/v1/admin/settings/logs/entra?subsystem=directory&kind=sync')->json('data'));
        $this->assertCount(2, $dir);

        // permissão: somente settings.manage; área desconhecida 404; segredo nunca aparece
        $this->as('auditor')->getJson('/api/v1/admin/settings/logs/entra')->assertForbidden();
        $this->as('gestor')->getJson('/api/v1/admin/settings/logs/email')->assertForbidden();
        $this->as('admin')->getJson('/api/v1/admin/settings/logs/outra')->assertNotFound();
        $this->assertStringNotContainsString('S3gredo-Do-App', (string) json_encode($this->as('admin')->getJson('/api/v1/admin/settings/logs/entra?limit=100')->json()));
    }
}
