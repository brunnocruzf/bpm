<?php

namespace Tests\Feature;

use App\Auth\PasswordPolicy;
use App\Models\AuditEvent;
use App\Models\AuthSession;
use App\Models\BpmGroup;
use App\Models\BpmGroupMembership;
use App\Models\LocalCredential;
use App\Models\LocalIdentityToken;
use App\Models\NotificationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class LocalIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
    }

    public function test_admin_invitation_activation_login_cookie_and_logout(): void
    {
        $created = $this->as('admin')->postJson('/api/v1/admin/managed-users', [
            'name' => 'Pessoa Local', 'email' => 'pessoa.local@example.test', 'username' => 'pessoa.local', 'area' => 'Operações', 'userCategory' => 'supplier',
        ])->assertCreated()->assertJsonPath('data.userCategory', 'supplier')->assertJsonPath('data.authSource', 'local')->assertJsonPath('data.isActive', false);
        $user = User::where('username', 'pessoa.local')->firstOrFail();
        $this->assertSame([], $user->roles);
        $this->assertSame(User::SOURCE_LOCAL, $user->auth_source);
        $credential = LocalCredential::where('user_id', $user->id)->firstOrFail();
        $this->assertNotSame('Ativa-Forte-2026!x', $credential->password_hash);
        $this->assertSame('invitation', LocalIdentityToken::where('user_id', $user->id)->value('purpose'));
        $this->as('admin')->patchJson('/api/v1/admin/managed-users/'.$user->id, ['active' => true])->assertStatus(409);

        $message = NotificationMessage::where('event', 'identity.invitation')->firstOrFail();
        $this->assertStringNotContainsString('token=', $message->body);
        $url = Crypt::decryptString($message->body);
        parse_str((string) parse_url(trim(explode("\n\n", $url)[1]), PHP_URL_QUERY), $query);
        $inviteToken = $query['token'];
        $this->assertNotSame($inviteToken, LocalIdentityToken::where('user_id', $user->id)->value('token_hash'));

        $password = 'Ativa-Forte-2026!x';
        $this->postJson('/api/v1/auth/local/activate', ['token' => $inviteToken, 'password' => $password])->assertOk();
        $this->assertTrue($user->fresh()->is_active);
        $this->assertTrue(PasswordPolicy::verify($password, $credential->fresh()->password_hash));
        $this->postJson('/api/v1/auth/local/activate', ['token' => $inviteToken, 'password' => $password])->assertStatus(422);

        $login = $this->postJson('/api/v1/auth/local/login', ['username' => 'pessoa.local', 'password' => $password])->assertOk()->assertJsonMissingPath('token');
        $sessionCookie = $login->getCookie('bpm_local_session', false);
        $csrfCookie = $login->getCookie('bpm_local_csrf', false);
        $this->assertNotNull($sessionCookie);
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertNotNull($csrfCookie);
        $this->assertSame('/', $csrfCookie->getPath());
        $opaque = $sessionCookie->getValue();
        $this->assertSame(hash('sha256', $opaque), AuthSession::where('user_id', $user->id)->value('token_hash'));
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withCredentials()->withUnencryptedCookies(['bpm_local_session' => $opaque])->getJson('/api/v1/me')->assertOk()->assertJsonPath('auth.method', 'local');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withCredentials()->withUnencryptedCookies(['bpm_local_session' => $opaque, 'bpm_local_csrf' => $csrfCookie->getValue()])
            ->withHeader('X-BPM-CSRF', $csrfCookie->getValue())->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertNotNull(AuthSession::where('user_id', $user->id)->firstOrFail()->revoked_at);
        $this->assertSame('supplier', $user->fresh()->user_category);
        $this->assertSame('supplier', AuditEvent::where('action', 'identity.local_user_created')->firstOrFail()->data['userCategory']);
        $this->assertTrue(AuditEvent::where('action', 'identity.local_user_created')->exists());
        $this->assertTrue(AuditEvent::where('action', 'identity.local_user_activated')->exists());
    }

    public function test_resending_the_invitation_does_not_allow_activating_an_account_that_never_accepted_it(): void
    {
        $this->as('admin')->postJson('/api/v1/admin/managed-users', [
            'name' => 'Pessoa Pendente', 'email' => 'pendente@example.test', 'username' => 'pessoa.pendente', 'userCategory' => 'customer',
        ])->assertCreated();
        $user = User::where('username', 'pessoa.pendente')->firstOrFail();
        $this->as('admin')->postJson('/api/v1/admin/managed-users/'.$user->id.'/invitation')->assertOk();
        $this->assertSame(2, LocalIdentityToken::where('user_id', $user->id)->where('purpose', 'invitation')->count());
        $this->assertNotNull(LocalIdentityToken::where('user_id', $user->id)->whereNotNull('used_at')->first(), 'o convite antigo é invalidado ao reenviar');

        $this->as('admin')->patchJson('/api/v1/admin/managed-users/'.$user->id, ['active' => true])
            ->assertStatus(409)->assertJsonPath('code', 'invitation_activation_required');
        $this->assertFalse($user->fresh()->is_active);
        $this->assertNull(LocalCredential::where('user_id', $user->id)->value('activated_at'));
    }

    public function test_an_account_that_accepted_the_invitation_can_be_disabled_and_reactivated_by_the_admin(): void
    {
        $this->as('admin')->postJson('/api/v1/admin/managed-users', [
            'name' => 'Pessoa Ativa', 'email' => 'ativa@example.test', 'username' => 'pessoa.ativa', 'userCategory' => 'internal',
        ])->assertCreated();
        $user = User::where('username', 'pessoa.ativa')->firstOrFail();
        $message = NotificationMessage::where('event', 'identity.invitation')->firstOrFail();
        $url = Crypt::decryptString($message->body);
        parse_str((string) parse_url(trim(explode("\n\n", $url)[1]), PHP_URL_QUERY), $query);
        $this->postJson('/api/v1/auth/local/activate', ['token' => $query['token'], 'password' => 'Ativa-Forte-2026!x'])->assertOk();
        $this->assertNotNull(LocalCredential::where('user_id', $user->id)->value('activated_at'));

        $this->as('admin')->patchJson('/api/v1/admin/managed-users/'.$user->id, ['active' => false])->assertOk();
        $this->as('admin')->patchJson('/api/v1/admin/managed-users/'.$user->id, ['active' => true])->assertOk()->assertJsonPath('data.isActive', true);
    }

    public function test_local_password_recovery_is_neutral_and_revokes_sessions(): void
    {
        $user = User::create(['name' => 'Pessoa Local', 'email' => 'pessoa@example.test', 'username' => 'pessoa', 'auth_source' => User::SOURCE_LOCAL, 'is_active' => true,
            'roles' => [], 'groups' => [], 'manual_groups' => [], 'token_roles' => [], 'token_groups' => [], 'directory_roles' => [], 'directory_groups' => []]);
        $oldPassword = 'Inicial-Forte-2026!x';
        LocalCredential::create(['user_id' => $user->id, 'password_hash' => PasswordPolicy::hash($oldPassword), 'password_changed_at' => now()]);
        $this->postJson('/api/v1/auth/local/login', ['username' => 'pessoa', 'password' => $oldPassword])->assertOk();
        $this->postJson('/api/v1/auth/local/forgot-password', ['username' => 'pessoa'])->assertOk()->assertJsonPath('message', 'Se os dados corresponderem a uma conta local ativa, enviaremos as instruções para o e-mail cadastrado.');
        $this->postJson('/api/v1/auth/local/forgot-password', ['username' => 'nao-existe'])->assertOk()->assertJsonPath('message', 'Se os dados corresponderem a uma conta local ativa, enviaremos as instruções para o e-mail cadastrado.');
        $resetMessage = NotificationMessage::where('event', 'identity.password_reset')->firstOrFail();
        $body = Crypt::decryptString($resetMessage->body);
        preg_match('/token=([^\\s]+)/', $body, $match);
        $this->assertNotEmpty($match[1] ?? null);
        $newPassword = 'Nova-Forte-2026!xy';
        $this->postJson('/api/v1/auth/local/reset-password', ['token' => $match[1], 'password' => $newPassword])->assertOk();
        $this->assertTrue(PasswordPolicy::verify($newPassword, LocalCredential::where('user_id', $user->id)->value('password_hash')));
        $this->assertNotNull(AuthSession::where('user_id', $user->id)->firstOrFail()->revoked_at);
        $this->postJson('/api/v1/auth/local/reset-password', ['token' => $match[1], 'password' => $newPassword])->assertStatus(422);
        $this->assertTrue(AuditEvent::where('action', 'identity.local_password_reset')->exists());
    }

    public function test_manual_group_membership_is_separate_from_directory_sources_and_admin_only(): void
    {
        $user = User::create(['name' => 'Pessoa Local', 'email' => 'pessoa@example.test', 'username' => 'pessoa', 'auth_source' => User::SOURCE_LOCAL, 'is_active' => true,
            'roles' => [], 'groups' => [], 'manual_groups' => [], 'token_roles' => [], 'token_groups' => [], 'directory_roles' => [], 'directory_groups' => []]);
        $group = BpmGroup::where('key', 'CQ')->firstOrFail();

        $this->as('cq')->getJson('/api/v1/admin/managed-users')->assertForbidden();
        $this->as('admin')->getJson('/api/v1/admin/managed-users?active=true&perPage=50')->assertOk();
        $this->as('admin')->postJson("/api/v1/admin/groups/{$group->key}/members", ['userIds' => [$user->id]])->assertCreated()->assertJsonPath('added', 1);
        $this->assertSame(['CQ'], $user->fresh()->manual_groups);
        $this->assertSame(['CQ'], $user->fresh()->groups);
        $this->assertSame('manual', BpmGroupMembership::firstOrFail()->source);

        $user->forceFill(['directory_groups' => ['CQ', 'GEF']])->save();
        $this->as('admin')->deleteJson("/api/v1/admin/groups/{$group->key}/members/{$user->id}")->assertNoContent();
        $this->assertSame([], $user->fresh()->manual_groups);
        $this->assertSame(['CQ', 'GEF'], $user->fresh()->groups);
        $this->assertTrue(AuditEvent::where('action', 'identity.local_group_member_added')->exists());
        $this->assertTrue(AuditEvent::where('action', 'identity.local_group_member_removed')->exists());
    }

    public function test_admin_can_create_group_and_assign_existing_active_members(): void
    {
        $user = User::create(['name' => 'Pessoa Local', 'email' => 'pessoa@example.test', 'username' => 'pessoa', 'auth_source' => User::SOURCE_LOCAL, 'is_active' => true,
            'roles' => [], 'groups' => [], 'manual_groups' => [], 'token_roles' => [], 'token_groups' => [], 'directory_roles' => [], 'directory_groups' => []]);

        $this->as('cq')->postJson('/api/v1/admin/groups', ['key' => 'RH_NOVO', 'name' => 'Recursos Humanos'])->assertForbidden();
        $this->as('admin')->postJson('/api/v1/admin/groups', [
            'key' => 'RH_NOVO', 'name' => 'Recursos Humanos', 'description' => 'Grupo criado pela administração',
        ])->assertCreated()->assertJsonPath('key', 'RH_NOVO');
        $this->as('admin')->postJson('/api/v1/admin/groups/RH_NOVO/members', ['userIds' => [$user->id]])->assertCreated()->assertJsonPath('added', 1);

        $this->assertSame(['RH_NOVO'], $user->fresh()->manual_groups);
        $this->assertTrue(AuditEvent::where('action', 'group.created')->exists());
        $this->as('admin')->getJson('/api/v1/admin/groups')->assertOk()->assertJsonFragment(['key' => 'RH_NOVO', 'name' => 'Recursos Humanos']);
    }

    public function test_manual_role_membership_is_audited_and_does_not_overwrite_directory_roles(): void
    {
        $user = User::create(['name' => 'Pessoa Local', 'email' => 'papel@example.test', 'username' => 'papel', 'auth_source' => User::SOURCE_LOCAL, 'is_active' => true,
            'roles' => [], 'groups' => [], 'manual_groups' => [], 'manual_roles' => [], 'token_roles' => [], 'token_groups' => [], 'directory_roles' => [], 'directory_groups' => []]);
        $role = 'Bpm.TaskWorker';

        $this->as('cq')->postJson("/api/v1/admin/roles/{$role}/users", ['userIds' => [$user->id]])->assertForbidden();
        $this->as('admin')->postJson("/api/v1/admin/roles/{$role}/users", ['userIds' => [$user->id]])->assertCreated()->assertJsonPath('added', 1);
        $this->assertSame([$role], $user->fresh()->manual_roles);
        $this->assertSame([$role], $user->fresh()->roles);
        $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$user->id)->assertOk()->assertJsonPath('data.access.roleSources.manual.0', $role);

        $user->forceFill(['directory_roles' => [$role]])->save();
        $this->as('admin')->deleteJson("/api/v1/admin/roles/{$role}/users/{$user->id}")->assertNoContent();
        $this->assertSame([], $user->fresh()->manual_roles);
        $this->assertSame([$role], $user->fresh()->roles);
        $this->assertTrue(AuditEvent::where('action', 'identity.manual_role_assigned')->exists());
        $this->assertTrue(AuditEvent::where('action', 'identity.manual_role_removed')->exists());

        $legacy = User::create(['name' => 'Conta Entra antiga', 'email' => 'antiga@example.test', 'username' => 'antiga', 'auth_source' => User::SOURCE_ENTRA, 'is_active' => true,
            'roles' => [], 'groups' => [], 'manual_groups' => [], 'manual_roles' => []]);
        $this->as('admin')->postJson("/api/v1/admin/roles/{$role}/users", ['userIds' => [$legacy->id]])->assertCreated();
        $this->assertSame([$role], $legacy->fresh()->roles);
        $this->as('admin')->deleteJson("/api/v1/admin/roles/{$role}/users/{$legacy->id}")->assertNoContent();
        $this->assertSame([], $legacy->fresh()->roles);

        $admin = $this->dev('admin');
        $this->as('admin')->postJson("/api/v1/admin/roles/{$role}/users", ['userIds' => [$admin->id]])->assertStatus(409)->assertJsonPath('code', 'self_role_assignment');
    }

    public function test_user_category_is_required_and_validated_on_creation(): void
    {
        $base = ['name' => 'Sem Tipo', 'email' => 'sem.tipo@example.test', 'username' => 'sem.tipo'];
        $this->as('admin')->postJson('/api/v1/admin/managed-users', $base)->assertStatus(422)->assertJsonPath('errors.userCategory.0', 'Selecione o tipo de usuário.');
        $this->as('admin')->postJson('/api/v1/admin/managed-users', [...$base, 'userCategory' => 'Entra'])->assertStatus(422)->assertJsonValidationErrorFor('userCategory', 'errors');
        $this->assertFalse(User::where('username', 'sem.tipo')->exists());
    }

    public function test_user_category_can_be_filtered_changed_for_local_accounts_only_and_is_audited(): void
    {
        foreach ([['a', 'supplier'], ['b', 'customer'], ['c', 'internal']] as [$n, $cat]) {
            $this->as('admin')->postJson('/api/v1/admin/managed-users', ['name' => "Pessoa $n", 'email' => "$n@example.test", 'username' => "pessoa.$n", 'userCategory' => $cat])->assertCreated();
        }
        $legacy = User::create(['name' => 'Legado', 'email' => 'legado@example.test', 'username' => 'legado', 'auth_source' => User::SOURCE_LOCAL, 'is_active' => true, 'roles' => [], 'groups' => []]);
        $this->as('admin')->getJson('/api/v1/admin/managed-users?category=supplier')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.userCategory', 'supplier');
        $this->as('admin')->getJson('/api/v1/admin/managed-users?source=local&category=none')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $legacy->id);
        $this->as('admin')->getJson('/api/v1/admin/managed-users?category=bogus')->assertStatus(422);

        $this->as('admin')->patchJson('/api/v1/admin/managed-users/'.$legacy->id, ['userCategory' => 'customer'])->assertOk()->assertJsonPath('data.userCategory', 'customer');
        $event = AuditEvent::where('action', 'identity.user_category_changed')->firstOrFail();
        $this->assertSame(['from' => null, 'to' => 'customer'], $event->diff['userCategory']);
        $this->as('admin')->patchJson('/api/v1/admin/managed-users/'.$legacy->id, ['userCategory' => 'nope'])->assertStatus(422);
        $this->as('admin')->patchJson('/api/v1/admin/managed-users/'.$legacy->id, [])->assertStatus(422);
        $this->as('cq')->patchJson('/api/v1/admin/managed-users/'.$legacy->id, ['userCategory' => 'internal'])->assertForbidden();

        $entra = User::create(['name' => 'Entra', 'email' => 'e@example.test', 'entra_oid' => 'o1', 'entra_tid' => 't1', 'auth_source' => User::SOURCE_ENTRA, 'is_active' => true, 'roles' => [], 'groups' => []]);
        $this->as('admin')->patchJson('/api/v1/admin/managed-users/'.$entra->id, ['userCategory' => 'internal'])->assertStatus(422);
        $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$legacy->id)->assertOk()->assertJsonPath('data.organization.userCategory', 'customer');
    }
}
