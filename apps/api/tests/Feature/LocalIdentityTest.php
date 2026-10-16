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
use Database\Seeders\DevUserSeeder;
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

    public function test_facets_attention_filters_and_local_integration_details_without_sensitive_data(): void
    {
        $this->as('cq')->getJson('/api/v1/admin/managed-users/facets')->assertForbidden();

        $this->as('admin')->postJson('/api/v1/admin/managed-users', ['name' => 'Convite Pendente', 'email' => 'pend@example.test', 'username' => 'convite.pendente', 'userCategory' => 'supplier'])->assertCreated();
        $pending = User::where('username', 'convite.pendente')->firstOrFail();
        $this->as('admin')->postJson('/api/v1/admin/managed-users', ['name' => 'Convite Vencido', 'email' => 'venc@example.test', 'username' => 'convite.vencido', 'userCategory' => 'customer'])->assertCreated();
        $expired = User::where('username', 'convite.vencido')->firstOrFail();
        LocalIdentityToken::where('user_id', $expired->id)->update(['expires_at' => now()->subMinute()]);
        $locked = User::create(['name' => 'Bloqueada', 'email' => 'bloq@example.test', 'username' => 'bloqueada', 'auth_source' => User::SOURCE_LOCAL, 'is_active' => true, 'roles' => [], 'groups' => []]);
        LocalCredential::create(['user_id' => $locked->id, 'password_hash' => 'x', 'password_changed_at' => now(), 'activated_at' => now(), 'failed_attempts' => 5, 'locked_until' => now()->addMinutes(10), 'last_login_ip' => '203.0.113.9']);
        $guest = User::create(['name' => 'Convidado', 'email' => 'g@example.test', 'entra_oid' => 'g-oid', 'entra_tid' => 't', 'auth_source' => User::SOURCE_ENTRA, 'user_type' => 'Guest', 'is_active' => true, 'roles' => [], 'groups' => []]);

        $facets = $this->as('admin')->getJson('/api/v1/admin/managed-users/facets')->assertOk()->json('data');
        $this->assertSame(1, $facets['attention']['invitation_pending']);
        $this->assertSame(1, $facets['attention']['invitation_expired']);
        $this->assertSame(1, $facets['attention']['locked']);
        $this->assertSame(1, $facets['attention']['guest']);
        $this->assertSame(1, $facets['attention']['login_only']);
        $this->assertSame(1, $facets['category']['supplier']);
        $this->assertContains('Bpm.Admin', array_column($facets['roles'], 'key'));

        $ids = fn (string $a) => collect($this->as('admin')->getJson('/api/v1/admin/managed-users?attention='.$a)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$pending->id], $ids('invitation_pending'));
        $this->assertSame([$expired->id], $ids('invitation_expired'));
        $this->assertSame([$locked->id], $ids('locked'));
        $this->assertSame([$guest->id], $ids('guest'));
        $this->as('admin')->getJson('/api/v1/admin/managed-users?attention=bogus')->assertStatus(422);

        $row = collect($this->as('admin')->getJson('/api/v1/admin/managed-users?attention=invitation_expired')->json('data'))->first();
        $this->assertSame('expired', $row['invitationStatus']);
        $this->assertFalse($row['invitationPending']);

        $detail = $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$pending->id)->assertOk()
            ->assertJsonPath('data.integration.source', 'local')->assertJsonPath('data.integration.entra', null)
            ->assertJsonPath('data.integration.local.invitation.status', 'pending')
            ->assertJsonPath('data.integration.local.invitation.invitedBy', 'Ana Administradora')
            ->assertJsonPath('data.integration.local.sessions.active', 0)->json('data.integration.local');
        $this->assertNull($detail['invitation']['acceptedAt']);
        $this->assertContains($detail['invitation']['delivery']['state'], ['queued', 'skipped', 'sent']);

        $lockedDetail = $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$locked->id)->assertOk()
            ->assertJsonPath('data.integration.local.invitation.status', 'accepted')->json('data.integration.local');
        $this->assertNotNull($lockedDetail['credential']['lockedUntil']);
        $this->assertSame(5, $lockedDetail['credential']['failedAttempts']);
        $this->assertStringNotContainsString('203.0.113.9', json_encode($lockedDetail));
        $this->assertStringNotContainsString('password_hash', json_encode($lockedDetail));

        $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$pending->id.'/activity')->assertOk()->assertJsonStructure(['data', 'meta' => ['currentPage', 'lastPage', 'total']]);
    }

    public function test_admin_can_remove_a_member_whose_access_comes_from_entra_and_the_block_survives_logins_and_can_be_restored(): void
    {
        $user = User::create(['name' => 'Pessoa Entra', 'email' => 'entra@example.test', 'entra_oid' => 'oid-1', 'entra_tid' => 'tid-1', 'auth_source' => User::SOURCE_ENTRA, 'is_active' => true,
            'roles' => ['Bpm.TaskWorker'], 'groups' => ['CQ', 'GEF'], 'manual_groups' => [], 'token_roles' => [], 'token_groups' => ['CQ'], 'directory_roles' => ['Bpm.TaskWorker'], 'directory_groups' => ['CQ', 'GEF']]);

        $members = $this->as('admin')->getJson('/api/v1/admin/groups/CQ/members')->assertOk();
        $this->assertSame('entra_token', collect($members->json('data'))->firstWhere('id', $user->id)['source']);

        $this->as('cq')->putJson("/api/v1/admin/groups/CQ/exclusions/{$user->id}")->assertForbidden();
        $this->as('admin')->putJson("/api/v1/admin/groups/CQ/exclusions/{$user->id}")->assertNoContent();
        $this->as('admin')->putJson("/api/v1/admin/groups/CQ/exclusions/{$user->id}")->assertNoContent(); // idempotente

        $fresh = $user->fresh();
        $this->assertSame(['GEF'], $fresh->groups);
        $this->assertSame(['CQ'], $fresh->excluded_groups);
        $this->assertSame(['CQ'], $fresh->token_groups, 'a origem (Entra) não é alterada');
        $this->assertSame(1, BpmGroupMembership::where('source', 'excluded')->count());
        $event = AuditEvent::where('action', 'identity.group_member_excluded')->firstOrFail();
        $this->assertSame(['entra_token', 'entra_sync'], $event->data['sources']);

        $after = $this->as('admin')->getJson('/api/v1/admin/groups/CQ/members')->assertOk();
        $this->assertNull(collect($after->json('data'))->firstWhere('id', $user->id));
        $this->assertSame([$user->id], array_column($after->json('excluded'), 'id'));
        $this->assertSame('Ana Administradora', $after->json('excluded.0.createdBy'));
        $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$user->id)->assertOk()->assertJsonPath('data.access.groupSources.excluded.0.key', 'CQ');

        // um novo login/sincronização recalcula o acesso a partir das origens e o bloqueio continua valendo
        $fresh->forceFill(['token_groups' => ['CQ'], 'directory_groups' => ['CQ', 'GEF']]);
        $fresh->recomputeEffectiveAccess();
        $fresh->save();
        $this->assertSame(['GEF'], $fresh->fresh()->groups);
        $this->as('admin')->deleteJson("/api/v1/admin/groups/CQ/members/{$user->id}")->assertNotFound();

        $this->as('cq')->deleteJson("/api/v1/admin/groups/CQ/exclusions/{$user->id}")->assertForbidden();
        $this->as('admin')->deleteJson("/api/v1/admin/groups/CQ/exclusions/{$user->id}")->assertNoContent();
        $this->assertEqualsCanonicalizing(['CQ', 'GEF'], $user->fresh()->groups);
        $this->assertSame([], $user->fresh()->excluded_groups);
        $this->assertSame(0, BpmGroupMembership::where('source', 'excluded')->count());
        $this->assertTrue(AuditEvent::where('action', 'identity.group_member_restored')->exists());
        $this->as('admin')->deleteJson("/api/v1/admin/groups/CQ/exclusions/{$user->id}")->assertNotFound();
    }

    public function test_removing_a_manual_plus_directory_member_removes_both_and_a_manual_only_member_leaves_no_block(): void
    {
        $both = User::create(['name' => 'Manual e Entra', 'email' => 'me@example.test', 'entra_oid' => 'oid-2', 'entra_tid' => 'tid-1', 'auth_source' => User::SOURCE_ENTRA, 'is_active' => true,
            'roles' => [], 'groups' => ['CQ'], 'manual_groups' => ['CQ'], 'token_roles' => [], 'token_groups' => [], 'directory_roles' => [], 'directory_groups' => ['CQ']]);
        $this->as('admin')->postJson('/api/v1/admin/groups/CQ/members', ['userIds' => [$both->id]])->assertCreated();
        $this->as('admin')->putJson("/api/v1/admin/groups/CQ/exclusions/{$both->id}")->assertNoContent();
        $this->assertSame([], $both->fresh()->groups);
        $this->assertSame([], $both->fresh()->manual_groups);
        $this->assertSame(0, BpmGroupMembership::where('source', 'manual')->count());
        $this->assertSame(1, BpmGroupMembership::where('source', 'excluded')->count());

        $local = User::create(['name' => 'Só Manual', 'email' => 'sm@example.test', 'username' => 'so.manual', 'auth_source' => User::SOURCE_LOCAL, 'is_active' => true,
            'roles' => [], 'groups' => [], 'manual_groups' => [], 'token_roles' => [], 'token_groups' => [], 'directory_roles' => [], 'directory_groups' => []]);
        $this->as('admin')->postJson('/api/v1/admin/groups/GEF/members', ['userIds' => [$local->id]])->assertCreated();
        $this->as('admin')->putJson("/api/v1/admin/groups/GEF/exclusions/{$local->id}")->assertNoContent();
        $this->assertSame([], $local->fresh()->groups);
        $this->assertSame([], $local->fresh()->excluded_groups, 'sem origem externa, remover o vínculo manual basta');
        $this->assertSame(0, BpmGroupMembership::where('user_id', $local->id)->count());

        $this->as('admin')->putJson("/api/v1/admin/groups/GEF/exclusions/{$local->id}")->assertNotFound();
        $this->as('admin')->postJson('/api/v1/admin/groups/CQ/members', ['userIds' => [$both->id]])->assertCreated();
        $this->assertSame(['CQ'], $both->fresh()->manual_groups);
        $this->assertSame(['CQ'], $both->fresh()->groups, 'adicionar manualmente desfaz o bloqueio local');
        $this->assertSame(0, BpmGroupMembership::where('source', 'excluded')->count());
        $this->assertTrue(AuditEvent::where('action', 'identity.group_member_restored')->where('data->via', 'manual_add')->exists());
    }

    public function test_a_legacy_demo_member_can_be_removed_and_the_dev_seed_keeps_the_block_but_the_emergency_account_cannot(): void
    {
        $carla = User::where('username', 'cq')->firstOrFail();
        $this->assertSame(['CQ'], $carla->groups);
        $this->as('admin')->putJson("/api/v1/admin/groups/CQ/exclusions/{$carla->id}")->assertNoContent();
        $this->assertSame([], $carla->fresh()->groups);
        $this->assertSame(['Bpm.Initiator', 'Bpm.TaskWorker'], $carla->fresh()->roles, 'remover do grupo não altera papéis');

        (new DevUserSeeder)->run();
        $this->assertSame([], $carla->fresh()->groups, 'o seed de desenvolvimento respeita o bloqueio');

        $this->as('admin')->deleteJson("/api/v1/admin/groups/CQ/exclusions/{$carla->id}")->assertNoContent();
        $this->assertSame(['CQ'], $carla->fresh()->groups, 'restaurar devolve o vínculo legado');

        $super = User::create(['name' => 'Emergência', 'username' => 'emergencia', 'auth_source' => User::SOURCE_SUPERADMIN, 'is_active' => true, 'roles' => [], 'groups' => ['CQ']]);
        $this->as('admin')->putJson("/api/v1/admin/groups/CQ/exclusions/{$super->id}")->assertStatus(422);
        $this->as('admin')->putJson('/api/v1/admin/groups/INEXISTENTE/exclusions/'.$carla->id)->assertNotFound();
    }
}
