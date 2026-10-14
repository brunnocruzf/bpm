<?php

namespace Tests\Feature;

use App\Auth\AuthMethods;
use App\Auth\PasswordPolicy;
use App\Models\AuditEvent;
use App\Models\AuthSession;
use App\Models\LocalCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/** Métodos de login simultâneos: Entra ID, visitante (demonstração) e SuperAdmin (emergência) — ADR-0015. */
class LoginMethodsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Emergencia-TMSA-2026!x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        config([
            'bpm.auth.superadmin.enabled' => true,
            'bpm.auth.superadmin.allowed_ips' => [],
            'bpm.auth.entra.tenant_id' => null,
            'bpm.auth.entra.audiences' => [],
        ]);
    }

    private function createSuperAdmin(string $password = self::PASSWORD, string $username = 'superadmin'): int
    {
        $file = tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($file, $password."\n");
        try {
            return Artisan::call('bpm:superadmin', ['action' => 'set-password', '--username' => $username, '--password-file' => $file]);
        } finally {
            @unlink($file);
        }
    }

    private function login(string $username = 'superadmin', string $password = self::PASSWORD, string $ip = '127.0.0.1'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/superadmin/login', ['username' => $username, 'password' => $password]);
    }

    private function bearer(string $token, string $ip = '127.0.0.1'): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_public_config_reflects_enabled_methods(): void
    {
        $this->getJson('/api/v1/public/auth-config')->assertOk()
            ->assertJsonPath('methods.entra', false)->assertJsonPath('methods.demo', true)
            ->assertJsonPath('methods.superadmin', true)->assertJsonPath('methods.demoUrl', null);

        config(['bpm.auth.driver' => 'entra', 'bpm.auth.superadmin.enabled' => false, 'bpm.auth.demo.url' => 'javascript:alert(1)',
            'bpm.auth.entra.tenant_id' => '11111111-2222-3333-4444-555555555555', 'bpm.auth.entra.audiences' => ['api://bpm']]);
        $this->getJson('/api/v1/public/auth-config')->assertJsonPath('methods.entra', true)->assertJsonPath('methods.demo', false)
            ->assertJsonPath('methods.superadmin', false)->assertJsonPath('methods.demoUrl', null);
        config(['bpm.auth.demo.url' => 'https://demo.bpm.tmsa.example']);
        $this->getJson('/api/v1/public/auth-config')->assertJsonPath('methods.demoUrl', 'https://demo.bpm.tmsa.example');
        $this->getJson('/api/v1/dev/users')->assertNotFound();
    }

    public function test_superadmin_login_session_and_logout_are_audited_without_secrets(): void
    {
        $this->assertSame(0, $this->createSuperAdmin());
        $user = User::where('username', 'superadmin')->firstOrFail();
        $this->assertSame(User::SOURCE_SUPERADMIN, $user->auth_source);
        $this->assertNotSame(self::PASSWORD, LocalCredential::firstOrFail()->password_hash);
        $this->assertTrue(password_verify(self::PASSWORD, LocalCredential::firstOrFail()->password_hash));

        $res = $this->login()->assertOk()->assertJsonPath('user.username', 'superadmin')->assertHeader('Cache-Control', 'no-store, private');
        $token = (string) $res->json('token');
        $this->assertStringStartsWith('bpmsa_', $token);
        $this->assertSame(hash('sha256', $token), AuthSession::firstOrFail()->token_hash, 'somente o hash do token é gravado');

        $this->bearer($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('auth.method', 'superadmin')
            ->assertJsonPath('permissions.settingsManage', true)->assertJsonPath('permissions.dataSensitive', false)
            ->assertJsonPath('auth.expiresAt', fn ($v) => is_string($v));
        $this->bearer($token)->getJson('/api/v1/admin/settings')->assertOk();

        $this->bearer($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->bearer($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame('logout', AuthSession::firstOrFail()->revoked_reason);

        $actions = AuditEvent::orderBy('occurred_at')->pluck('action')->all();
        foreach (['auth.superadmin_password_set', 'auth.login', 'auth.logout'] as $a) {
            $this->assertContains($a, $actions);
        }
        $set = AuditEvent::where('action', 'auth.superadmin_password_set')->firstOrFail();
        $this->assertSame('system', $set->actor_type);
        $all = json_encode(AuditEvent::all()->toArray()).json_encode(AuthSession::all()->makeVisible('token_hash')->toArray());
        $this->assertStringNotContainsString(self::PASSWORD, $all);
        $this->assertStringNotContainsString($token, $all);
    }

    public function test_wrong_password_is_generic_and_locks_the_account(): void
    {
        config(['bpm.auth.superadmin.lockout_threshold' => 3, 'bpm.auth.superadmin.max_attempts' => 100]);
        $this->createSuperAdmin();
        $generic = $this->login('superadmin', 'errada-Senha-123!')->assertUnauthorized()->json('detail');
        $this->assertSame($generic, $this->login('nao-existe', 'errada-Senha-123!')->assertUnauthorized()->json('detail'), 'mesma resposta para usuário inexistente');
        $this->login('superadmin', 'errada-Senha-123!')->assertUnauthorized();
        $this->login('superadmin', 'errada-Senha-123!')->assertUnauthorized();
        $this->assertTrue(LocalCredential::firstOrFail()->isLocked());
        $this->assertTrue(AuditEvent::where('action', 'auth.superadmin_locked')->exists());

        // bloqueada: nem a senha correta entra (e a resposta continua genérica)
        $this->assertSame($generic, $this->login()->assertUnauthorized()->json('detail'));
        $this->assertSame('locked', AuditEvent::where('action', 'auth.login_failed')->orderByDesc('occurred_at')->first()->data['reason'] ?? null);

        Artisan::call('bpm:superadmin', ['action' => 'unlock']);
        $this->login()->assertOk();

        // tentativa não autenticada nunca é atribuída ao titular da conta
        $failed = AuditEvent::where('action', 'auth.login_failed')->get();
        $this->assertTrue($failed->every(fn (AuditEvent $e) => $e->actor_type === 'system' && $e->actor_id === null));
    }

    public function test_typed_unknown_username_is_never_stored_and_attempts_are_rate_limited(): void
    {
        config(['bpm.auth.superadmin.max_attempts' => 2]);
        $this->createSuperAdmin();
        $this->login('Minha-Senha-Digitada-No-Campo', 'x')->assertUnauthorized();
        $this->assertStringNotContainsString('Minha-Senha-Digitada', json_encode(AuditEvent::all()->toArray()));

        $this->login('superadmin', 'errada-1-Aa!')->assertUnauthorized();
        $this->login('superadmin', 'errada-2-Aa!')->assertUnauthorized();
        $this->login()->assertStatus(429)->assertJsonPath('code', 'login_throttled');
        $this->assertTrue(AuditEvent::where('action', 'auth.login_failed')->get()->contains(fn (AuditEvent $e) => ($e->data['reason'] ?? null) === 'throttled'));
    }

    public function test_disabled_method_ip_allowlist_and_session_expiry(): void
    {
        $this->createSuperAdmin();
        $token = (string) $this->login()->assertOk()->json('token');

        config(['bpm.auth.superadmin.enabled' => false]);
        $this->login()->assertNotFound();
        $this->bearer($token)->getJson('/api/v1/me')->assertUnauthorized();

        config(['bpm.auth.superadmin.enabled' => true, 'bpm.auth.superadmin.allowed_ips' => ['10.0.0.0/8']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.0.5'])->getJson('/api/v1/public/auth-config')->assertJsonPath('methods.superadmin', false);
        $this->login(ip: '192.168.0.5')->assertNotFound();
        $this->bearer($token, '192.168.0.5')->getJson('/api/v1/me')->assertUnauthorized();
        $ok = (string) $this->login(ip: '10.1.2.3')->assertOk()->json('token');
        $this->bearer($ok, '10.1.2.3')->getJson('/api/v1/me')->assertOk();

        // inatividade
        $this->travel(31)->minutes();
        $this->bearer($ok, '10.1.2.3')->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame('idle_timeout', AuthSession::where('token_hash', hash('sha256', $ok))->firstOrFail()->revoked_reason);

        // expiração absoluta, mesmo com uso contínuo
        config(['bpm.auth.superadmin.session_minutes' => 20]);
        $abs = (string) $this->login(ip: '10.1.2.3')->assertOk()->json('token');
        $this->travel(15)->minutes();
        $this->bearer($abs, '10.1.2.3')->getJson('/api/v1/me')->assertOk();
        $this->travel(10)->minutes();
        $this->bearer($abs, '10.1.2.3')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_new_password_revokes_sessions_and_session_limit_applies(): void
    {
        $this->createSuperAdmin();
        $tokens = [];
        for ($i = 0; $i < 4; $i++) {
            $tokens[] = (string) $this->login()->assertOk()->json('token');
            $this->travel(1)->seconds();
        }
        $this->bearer($tokens[0])->getJson('/api/v1/me')->assertUnauthorized();
        $this->bearer($tokens[3])->getJson('/api/v1/me')->assertOk();

        $this->createSuperAdmin('Outra-Senha-Forte-2026#');
        $this->bearer($tokens[3])->getJson('/api/v1/me')->assertUnauthorized();
        $this->login()->assertUnauthorized();
        $this->login('superadmin', 'Outra-Senha-Forte-2026#')->assertOk();
    }

    public function test_demo_tokens_never_reach_superadmin_or_entra_accounts(): void
    {
        $this->createSuperAdmin();
        $this->withHeader('Authorization', 'Bearer dev:superadmin')->getJson('/api/v1/me')->assertUnauthorized();
        User::create(['name' => 'Pessoa Entra', 'username' => 'pessoa', 'entra_oid' => 'oid-1', 'entra_tid' => 'tid-1', 'auth_source' => User::SOURCE_ENTRA, 'roles' => ['Bpm.Admin'], 'groups' => []]);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer dev:pessoa')->getJson('/api/v1/me')->assertUnauthorized();

        $users = collect($this->getJson('/api/v1/dev/users')->assertOk()->json())->pluck('username');
        $this->assertContains('admin', $users);
        $this->assertNotContains('superadmin', $users);
        $this->assertNotContains('pessoa', $users);

        $this->as('admin')->getJson('/api/v1/me')->assertOk()->assertJsonPath('auth.method', 'demo');
        $this->as('admin')->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_command_validates_password_policy_and_username_collisions(): void
    {
        $this->assertNotSame(0, $this->createSuperAdmin('curta'));
        $this->assertNotSame(0, $this->createSuperAdmin('somenteletrasminusculas'));
        $this->assertNotSame(0, $this->createSuperAdmin('Superadmin-2026-Forte!', 'superadmin'));
        $this->assertNotSame(0, $this->createSuperAdmin(self::PASSWORD, 'admin'), 'não converte conta de demonstração');
        $this->assertSame(User::SOURCE_DEMO, User::where('username', 'admin')->value('auth_source'));
        $this->assertSame([], PasswordPolicy::violations(PasswordPolicy::generate(), 'superadmin'));

        $this->assertSame(0, Artisan::call('bpm:superadmin', ['action' => 'set-password', '--generate' => true]));
        $this->assertSame(0, Artisan::call('bpm:superadmin', ['action' => 'disable']));
        $this->assertFalse(User::where('username', 'superadmin')->firstOrFail()->is_active);
        $this->assertTrue(AuditEvent::where('action', 'auth.superadmin_disabled')->exists());
    }

    public function test_login_page_links_accept_only_https_or_mailto(): void
    {
        $this->getJson('/api/v1/public/branding')->assertOk()->assertJsonPath('links', ['support' => null, 'privacy' => null, 'help' => null]);
        $this->as('admin')->putJson('/api/v1/admin/settings/branding', ['links' => ['support' => 'javascript:alert(1)', 'privacy' => 'http://tmsa.example/privacidade', 'help' => 'mailto:x']])
            ->assertStatus(422)->assertJsonValidationErrors(['links.support', 'links.privacy', 'links.help'], 'errors');
        $this->as('admin')->putJson('/api/v1/admin/settings/branding', ['links' => ['support' => 'mailto:suporte@tmsa.example', 'privacy' => 'https://tmsa.example/privacidade', 'help' => '']])->assertOk();
        $this->getJson('/api/v1/public/branding')->assertJsonPath('links.support', 'mailto:suporte@tmsa.example')
            ->assertJsonPath('links.privacy', 'https://tmsa.example/privacidade')->assertJsonPath('links.help', null);
    }

    public function test_production_refuses_demo_and_requires_ip_allowlist_for_superadmin(): void
    {
        $this->app['env'] = 'production';
        try {
            config(['bpm.auth.driver' => 'dev']);
            $this->assertThrows(fn () => AuthMethods::assertSafeConfiguration(), RuntimeException::class);
            config(['bpm.auth.driver' => 'entra', 'bpm.auth.demo.enabled' => true]);
            $this->assertThrows(fn () => AuthMethods::assertSafeConfiguration(), RuntimeException::class);
            config(['bpm.auth.demo.enabled' => false, 'bpm.auth.superadmin.enabled' => true, 'bpm.auth.superadmin.allowed_ips' => []]);
            $this->assertThrows(fn () => AuthMethods::assertSafeConfiguration(), RuntimeException::class);
            config(['bpm.auth.superadmin.allowed_ips' => ['10.20.0.0/16']]);
            AuthMethods::assertSafeConfiguration();
            $this->assertFalse(AuthMethods::demoEnabled());
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
