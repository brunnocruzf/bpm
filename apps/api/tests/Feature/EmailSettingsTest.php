<?php

namespace Tests\Feature;

use App\Mail\HostPolicy;
use App\Mail\MailException;
use App\Mail\SmtpMailProvider;
use App\Models\AppSetting;
use App\Models\AppSettingRevision;
use App\Models\AuditEvent;
use App\Models\NotificationMessage;
use App\Models\SecretEntry;
use App\Notifications\NotificationSender;
use App\Notifications\Notifier;
use App\Settings\Types\EmailSettings;
use App\Support\ApiProblem;
use App\Support\Correlation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FakeMail;
use Tests\TestCase;

class EmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private FakeMail $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->mail = FakeMail::install();
    }

    private function saveSmtp(array $smtp = [], array $extra = [])
    {
        return $this->as('admin')->putJson('/api/v1/admin/settings/email', [
            'provider' => 'smtp', 'fromAddress' => 'bpm@tmsa.example', 'fromName' => 'BPM', 'replyTo' => 'suporte@tmsa.example',
            'smtp' => ['host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'starttls', 'auth' => true, 'username' => 'bpm@tmsa.example', ...$smtp],
            ...$extra,
        ]);
    }

    public function test_validation_depends_on_provider_type(): void
    {
        $this->as('admin')->putJson('/api/v1/admin/settings/email', ['provider' => 'sendgrid'])->assertStatus(422)->assertJsonValidationErrors('provider');
        $this->as('admin')->putJson('/api/v1/admin/settings/email', ['provider' => 'smtp', 'fromAddress' => 'nao-e-email'])->assertStatus(422)->assertJsonValidationErrors('fromAddress');
        // SMTP sem senha fica incompleto (não "configurado")
        $this->saveSmtp()->assertOk()->assertJsonPath('status', 'incomplete');
        $this->as('admin')->putJson('/api/v1/admin/settings/email', ['provider' => 'm365_graph', 'm365' => ['tenantId' => 'x']])->assertStatus(422)->assertJsonValidationErrors('m365.tenantId');
        $this->as('admin')->putJson('/api/v1/admin/settings/email', ['provider' => 'disabled'])->assertOk()->assertJsonPath('status', 'disabled');
    }

    public function test_mailpit_is_refused_in_production(): void
    {
        $type = app(EmailSettings::class);
        $this->app['env'] = 'production';
        $prodDefault = $type->defaults()['provider'];
        try {
            $type->normalize(['provider' => 'mailpit'], $type->defaults(), $this->dev('admin'));
            $this->fail('Mailpit deveria ser recusado em produção');
        } catch (ApiProblem $e) {
            $this->assertArrayHasKey('provider', $e->errors);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame('disabled', $prodDefault, 'padrão em produção é desabilitado');
    }

    public function test_host_policy_blocks_ssrf_targets_and_plaintext(): void
    {
        foreach (['169.254.169.254' => 587, '10.0.0.5' => 587, '127.0.0.1' => 587, 'metadata.google.internal' => 587, 'smtp.office365.com' => 22] as $host => $port) {
            $this->saveSmtp(['host' => $host, 'port' => $port])->assertStatus(422)->assertJsonValidationErrors('smtp.host');
        }
        $this->saveSmtp(['encryption' => 'none'])->assertStatus(422);
        // relay interno declarado pela plataforma (env) é a exceção deliberada
        HostPolicy::assertAllowed('relay.interno.tmsa', 25, 'none');
        $this->expectException(MailException::class);
        HostPolicy::assertAllowed('fd00:ec2::254', 587, 'starttls');
    }

    public function test_secret_is_encrypted_masked_and_never_returned_or_logged(): void
    {
        Log::spy();
        $res = $this->saveSmtp(['password' => 'SenhaSuperSecreta#1'])->assertOk();
        $this->assertStringNotContainsString('SenhaSuperSecreta', $res->getContent());
        $res->assertJsonPath('value.smtp.password.configured', true)->assertJsonPath('status', 'test_pending');
        $this->assertArrayNotHasKey('passwordRef', $res->json('value.smtp'));

        $this->assertStringNotContainsString('SenhaSuperSecreta', (string) SecretEntry::first()->ciphertext);
        $this->assertStringNotContainsString('SenhaSuperSecreta', json_encode(AppSetting::all()->toArray()));
        $this->assertStringNotContainsString('SenhaSuperSecreta', json_encode(AppSettingRevision::all()->toArray()));
        $this->assertStringNotContainsString('SenhaSuperSecreta', json_encode(AuditEvent::all()->toArray()));
        $this->assertTrue(AuditEvent::where('action', 'settings.secret_updated')->exists());
        $this->as('admin')->getJson('/api/v1/admin/settings/email')->assertDontSee('SenhaSuperSecreta');
        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($m) => str_contains((string) $m, 'SenhaSuperSecreta'))]);
    }

    public function test_connection_test_reports_tls_failure_with_sanitized_code(): void
    {
        $this->saveSmtp(['password' => 'x1'])->assertOk();
        $this->mail->testChecks = [['name' => 'Conexão, TLS e autenticação SMTP', 'ok' => false, 'code' => 'tls_failure', 'message' => 'Falha na negociação TLS/validação do certificado do servidor SMTP.']];
        $this->as('admin')->postJson('/api/v1/admin/settings/email/test-connection')->assertOk()
            ->assertJsonPath('status', 'error')->assertJsonPath('lastValidation.code', 'tls_failure')->assertJsonPath('lastValidation.ok', false);

        // classificação real das exceções do transporte Symfony
        $this->assertSame('tls_failure', SmtpMailProvider::classify(new TransportException('stream_socket_enable_crypto(): SSL operation failed with code 1. certificate verify failed'))->errorCode);
        $this->assertSame('auth_failed', SmtpMailProvider::classify(new TransportException('Expected response code "235" but got code "535"', 535))->errorCode);
        $this->assertTrue(SmtpMailProvider::classify(new TransportException('Connection could not be established with host "x"'))->transient);
        $this->assertSame('provider_error', SmtpMailProvider::classify(new RuntimeException('?'))->errorCode);
    }

    public function test_test_email_goes_through_outbox_and_confirms_result(): void
    {
        $this->saveSmtp(['password' => 'x1'])->assertOk();
        $this->as('admin')->postJson('/api/v1/admin/settings/email/test', ['to' => 'invalido'])->assertStatus(422);
        $id = $this->as('admin')->postJson('/api/v1/admin/settings/email/test', ['to' => 'teste@tmsa.example'])->assertStatus(202)->assertJsonPath('state', 'queued')->json('id');
        $this->assertSame([], $this->mail->sent, 'nada é enviado na requisição: somente pelo worker');
        $this->drainNotifications();
        $this->assertSame(['teste@tmsa.example'], $this->mail->recipients());
        $this->as('admin')->getJson("/api/v1/admin/settings/email/test/$id")->assertOk()->assertJsonPath('state', 'sent')->assertJsonPath('providerMessageId', 'fake-1');
        $this->as('admin')->getJson('/api/v1/admin/settings/email')->assertJsonPath('status', 'configured')->assertJsonPath('lastValidation.code', 'test_email_sent');
    }

    public function test_transient_failures_retry_with_backoff_then_dead_letter_and_manual_retry(): void
    {
        $this->saveSmtp(['password' => 'x1'])->assertOk();
        Correlation::set('corr-mail-0001');
        $notifier = app(Notifier::class);
        $notifier->toGroup('GEF', 'stage.completed', 'Assunto', 'Corpo', null, 'k:retry:1');
        $notifier->toGroup('GEF', 'stage.completed', 'Assunto', 'Corpo', null, 'k:retry:1');
        $this->assertSame(1, NotificationMessage::where('idempotency_key', 'k:retry:1')->count(), 'idempotência');

        $msg = NotificationMessage::where('idempotency_key', 'k:retry:1')->first();
        $msg->update(['max_attempts' => 2]);
        $this->mail->failures = [new MailException('timeout', 'Tempo esgotado.', true), new MailException('timeout', 'Tempo esgotado.', true)];
        $this->drainNotifications();
        $msg->refresh();
        $this->assertSame(NotificationMessage::RETRYING, $msg->state);
        $this->assertTrue($msg->available_at->isFuture(), 'backoff agenda nova tentativa');
        $this->drainNotifications();
        $msg->refresh();
        $this->assertSame(NotificationMessage::FAILED, $msg->state, 'esgotou: dead-letter');
        $this->assertSame('timeout', $msg->error_code);
        $this->assertSame('corr-mail-0001', AuditEvent::where('action', 'notification.failed')->first()->correlation_id);

        $this->as('cq')->postJson("/api/v1/admin/notifications/{$msg->id}/retry")->assertForbidden();
        $this->as('gestor')->getJson('/api/v1/admin/notifications?state=failed')->assertOk()->assertJsonPath('data.0.id', $msg->id)->assertJsonPath('data.0.recipientHint', 'g***f@bpm.tmsa.local');
        $this->as('gestor')->postJson("/api/v1/admin/notifications/{$msg->id}/retry")->assertOk();
        $this->drainNotifications();
        $this->assertSame(NotificationMessage::SENT, $msg->fresh()->state);
    }

    public function test_permanent_failure_goes_straight_to_dead_letter(): void
    {
        $this->saveSmtp(['password' => 'x1'])->assertOk();
        app(Notifier::class)->toGroup('GEF', 'stage.completed', 'A', 'B', null, 'k:perm');
        $this->mail->failures = [new MailException('recipient_rejected', 'Recusado (550).', false)];
        $this->drainNotifications();
        $msg = NotificationMessage::where('idempotency_key', 'k:perm')->first();
        $this->assertSame(NotificationMessage::FAILED, $msg->state);
        $this->assertSame(1, $msg->attempts);
    }

    public function test_disabled_provider_skips_without_sending(): void
    {
        $this->as('admin')->putJson('/api/v1/admin/settings/email', ['provider' => 'disabled'])->assertOk();
        app(Notifier::class)->toGroup('GEF', 'stage.completed', 'A', 'B', null, 'k:off');
        app(NotificationSender::class)->dispatchDue();
        $this->assertSame(NotificationMessage::SKIPPED, NotificationMessage::where('idempotency_key', 'k:off')->first()->state);
        $this->assertSame([], $this->mail->sent);
    }
}
