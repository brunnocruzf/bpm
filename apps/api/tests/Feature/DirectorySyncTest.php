<?php

namespace Tests\Feature;

use App\Identity\DirectorySyncService;
use App\Identity\EntraAppTokenProvider;
use App\Identity\Graph\GraphException;
use App\Identity\Graph\HttpGraphClient;
use App\Identity\Graph\MockGraphClient;
use App\Models\AuditEvent;
use App\Models\DirectoryGroup;
use App\Models\DirectoryMembership;
use App\Models\DirectorySyncCursor;
use App\Models\DirectorySyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGraph;
use Tests\TestCase;

class DirectorySyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeGraph $graph;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        Sleep::fake();
        $this->graph = FakeGraph::install();
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', [
            'enabled' => true, 'transport' => 'graph', 'tenantId' => FakeGraph::TENANT, 'clientId' => 'cccccccc-0000-4000-8000-000000000001',
            'credentialType' => 'client_secret', 'clientSecret' => 'S3gredo-Do-App', 'clientSecretApproval' => 'SEC-2026-042',
            'groups' => [['id' => FakeGraph::G1, 'name' => 'BPM-CQ'], ['id' => FakeGraph::G2, 'name' => 'BPM-GEF']],
        ])->assertOk()->assertJsonPath('status', 'test_pending');
    }

    private function sync(string $mode = 'delta'): DirectorySyncRun
    {
        $id = $mode === 'preview'
            ? $this->as('admin')->postJson('/api/v1/admin/entra/sync/preview')->assertStatus(202)->json('id')
            : $this->as('admin')->postJson('/api/v1/admin/entra/sync/run', ['mode' => $mode])->assertStatus(202)->json('id');
        app(DirectorySyncService::class)->execute($id);

        return DirectorySyncRun::find($id);
    }

    private function seedDirectory(): array
    {
        $a = $this->graph->user('1');
        $b = $this->graph->user('2');
        $c = $this->graph->user('3');
        $guest = $this->graph->user('4', ['userType' => 'Guest']);
        $disabled = $this->graph->user('5', ['accountEnabled' => false]);
        $outside = $this->graph->user('6'); // não pertence aos grupos selecionados
        $this->graph->members[FakeGraph::G1] = [$a, $b, $guest];
        $this->graph->members[FakeGraph::G2] = [$c, $disabled];

        return compact('a', 'b', 'c', 'guest', 'disabled', 'outside');
    }

    public function test_initial_paginated_load_imports_scope_and_saves_delta_link_only_at_the_end(): void
    {
        $ids = $this->seedDirectory();
        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $this->assertTrue($run->cursor_advanced);
        $this->assertSame(1, $run->totals['full']);
        $this->assertSame(3, $run->totals['created'], 'membros elegíveis; guest e desabilitado excluídos por padrão');
        $this->assertSame(2, $run->totals['excluded']);
        $this->assertTrue($this->graph->calledWith('$skiptoken=p2'), 'seguiu @odata.nextLink');
        $this->assertSame(2, DirectoryGroup::count());
        $this->assertSame(3, DirectoryMembership::whereNull('removed_at')->count());
        $this->assertNull(User::where('entra_oid', $ids['outside'])->first(), 'fora dos grupos selecionados não é importado');
        $this->assertNull(User::where('entra_oid', $ids['guest'])->first());

        $cursor = DirectorySyncCursor::where('resource', 'users')->first();
        $this->assertStringContainsString('deltatoken=u-1', (string) $cursor->delta_link);
        $this->assertNull($cursor->next_link);
        $this->assertStringNotContainsString('deltatoken', json_encode(DirectorySyncCursor::all()->toArray()), 'cursor nunca é serializado');
        $this->assertStringNotContainsString('S3gredo-Do-App', json_encode(AuditEvent::all()->toArray()));

        // grupo importado sem mapeamento não concede nada
        $user = User::where('entra_oid', $ids['a'])->first();
        $this->assertSame([], $user->roles);
        $this->assertSame(FakeGraph::TENANT, $user->entra_tid);
        $this->as('admin')->getJson('/api/v1/admin/entra/sync/runs')->assertOk()->assertJsonPath('overview.cursor.present', true)->assertJsonMissingPath('data.0.plan');
    }

    public function test_manager_relationship_is_tracked_and_resolved_when_manager_is_imported_later(): void
    {
        $ids = $this->seedDirectory();
        $managerOid = $this->graph->user('7');
        $this->graph->managerAssignments[$ids['a']] = $managerOid;

        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $this->assertTrue($this->graph->calledWith('users/delta?$select=id,displayName,userPrincipalName,mail,department,accountEnabled,userType,manager'));
        $report = User::where('entra_oid', $ids['a'])->firstOrFail();
        $this->assertSame($managerOid, $report->manager_entra_oid);
        $this->assertNull($report->manager_user_id, 'a referência fica pendente sem importar usuários fora do escopo');
        $this->assertNull(User::where('entra_oid', $managerOid)->first());
        $this->as('admin')->getJson('/api/v1/admin/managed-users?'.http_build_query(['q' => 'Usuário 1']))
            ->assertOk()->assertJsonPath('data.0.managerPending', true)->assertJsonPath('data.0.manager', null);

        // O manager entra no escopo depois; o vínculo pendente é resolvido por tenant + object ID.
        $this->graph->members[FakeGraph::G1][] = $managerOid;
        $this->graph->groupChanges = [['id' => FakeGraph::G1, 'members@delta' => [['@odata.type' => '#microsoft.graph.user', 'id' => $managerOid]]]];
        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $manager = User::where('entra_oid', $managerOid)->firstOrFail();
        $report->refresh();
        $this->assertSame($manager->id, $report->manager_user_id);
        $this->assertSame($manager->name, $report->manager->name);

        $this->as('admin')->getJson('/api/v1/admin/managed-users?'.http_build_query(['q' => 'Usuário 1']))
            ->assertOk()->assertJsonPath('data.0.manager.id', $manager->id)->assertJsonPath('data.0.manager.name', $manager->name);
        $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$manager->id)
            ->assertOk()->assertJsonPath('data.identity.id', $manager->id);
        $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$report->id)
            ->assertOk()->assertJsonPath('data.organization.manager', $manager->name);

        // A remoção do vínculo também chega como manager@delta vazio.
        $this->graph->userChanges = [['id' => $ids['a'], 'manager@delta' => []]];
        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $this->assertNull($report->fresh()->manager_entra_oid);
        $this->assertNull($report->fresh()->manager_user_id);
    }

    public function test_all_directory_scope_needs_confirmation_and_imports_every_group_and_member_user(): void
    {
        $ids = $this->seedDirectory();
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', ['scope' => 'all', 'groups' => []])
            ->assertStatus(422)->assertJsonPath('errors.scope.0', fn ($m) => str_contains($m, 'Confirme'));
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', ['scope' => 'all', 'confirmAllDirectory' => true, 'groups' => []])
            ->assertOk()->assertJsonPath('status', 'test_pending')->assertJsonPath('value.scope', 'all');

        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $this->assertTrue($this->graph->calledWith('groups/delta?$select='), 'todos os grupos: delta sem $filter');
        $this->assertFalse($this->graph->calledWith("id eq '"));
        $this->assertSame(2, DirectoryGroup::whereNull('removed_at')->count());
        $this->assertSame(4, $run->totals['created'], 'todos os usuários do tenant (inclusive sem grupo); guest e desabilitado seguem excluídos');
        $this->assertNotNull(User::where('entra_oid', $ids['outside'])->first());
        $this->assertNull(User::where('entra_oid', $ids['guest'])->first());

        // mapeamento continua explícito: só o grupo mapeado concede acesso
        $this->as('admin')->postJson('/api/v1/admin/entra/mappings', ['entraGroupId' => FakeGraph::G2, 'entraGroupName' => 'BPM-GEF', 'tmsaGroupKey' => 'GEF', 'tmsaRoles' => ['Bpm.TaskWorker']])->assertCreated();
        $this->assertSame(['Bpm.TaskWorker'], User::where('entra_oid', $ids['c'])->first()->roles);
        $this->assertSame([], User::where('entra_oid', $ids['a'])->first()->roles);
        $this->as('admin')->getJson('/api/v1/admin/entra/groups')->assertOk()->assertJsonPath('data.0.selected', true);

        // recarga completa: grupo que sumiu do tenant é marcado como removido e os vínculos saem
        unset($this->graph->members[FakeGraph::G2]);
        $run = $this->sync('full');
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $this->assertSame(1, $run->totals['groups_removed']);
        $this->assertNotNull(DirectoryGroup::where('object_id', FakeGraph::G2)->value('removed_at'));
        $this->assertSame([], User::where('entra_oid', $ids['c'])->first()->roles);

        // voltar ao escopo por grupos não exige confirmação e invalida o delta (nova carga completa)
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', ['scope' => 'groups', 'groups' => [['id' => FakeGraph::G1]]])->assertOk();
        $this->assertSame(1, $this->sync()->totals['full']);
    }

    public function test_role_mapping_is_explicit_and_privileged_roles_need_confirmation(): void
    {
        $ids = $this->seedDirectory();
        $this->sync();
        $this->as('admin')->postJson('/api/v1/admin/entra/mappings', ['entraGroupId' => FakeGraph::G1, 'tmsaRoles' => ['Bpm.Admin']])->assertStatus(422)->assertJsonValidationErrors('privileged');
        $this->as('admin')->postJson('/api/v1/admin/entra/mappings', ['entraGroupId' => FakeGraph::G1, 'tmsaRoles' => ['Bpm.Inexistente']])->assertStatus(422);
        $this->as('admin')->postJson('/api/v1/admin/entra/mappings', ['entraGroupId' => FakeGraph::G1, 'entraGroupName' => 'BPM-CQ', 'tmsaGroupKey' => 'CQ', 'tmsaRoles' => ['Bpm.TaskWorker', 'Bpm.Initiator']])->assertCreated();
        $user = User::where('entra_oid', $ids['a'])->first();
        $this->assertEqualsCanonicalizing(['Bpm.Initiator', 'Bpm.TaskWorker'], $user->roles);
        $this->assertSame(['CQ'], $user->groups);
        $this->assertSame([], User::where('entra_oid', $ids['c'])->first()->roles, 'outro grupo sem mapeamento');
        $this->assertTrue(AuditEvent::where('action', 'directory.user_roles_changed')->where('entity_id', $user->id)->exists());

        $this->as('admin')->postJson('/api/v1/admin/entra/mappings', ['entraGroupId' => FakeGraph::G2, 'tmsaRoles' => ['Bpm.Auditor'], 'privileged' => true, 'justification' => 'Auditoria interna aprovada no chamado 123'])->assertCreated();
        $this->assertContains('Bpm.Auditor', User::where('entra_oid', $ids['c'])->first()->roles);
    }

    public function test_delta_round_uses_delta_link_disables_removed_users_and_removes_memberships_without_deleting(): void
    {
        $ids = $this->seedDirectory();
        $this->sync();
        $this->as('admin')->postJson('/api/v1/admin/entra/mappings', ['entraGroupId' => FakeGraph::G1, 'tmsaGroupKey' => 'CQ', 'tmsaRoles' => ['Bpm.TaskWorker']])->assertCreated();
        $a = User::where('entra_oid', $ids['a'])->first();

        $this->graph->userChanges = [['id' => $ids['b'], '@removed' => ['reason' => 'deleted']], ['id' => $ids['c'], 'displayName' => 'Usuário 3 (renomeado)', 'accountEnabled' => true]];
        $this->graph->groupChanges = [['id' => FakeGraph::G1, 'members@delta' => [['@odata.type' => '#microsoft.graph.user', 'id' => $ids['a'], '@removed' => ['reason' => 'deleted']]]]];
        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $this->assertSame(0, $run->totals['full']);
        $this->assertTrue($this->graph->calledWith('users/delta?$deltatoken=u-1'));

        $b = User::where('entra_oid', $ids['b'])->first();
        $this->assertNotNull($b, 'usuário excluído no diretório não é apagado');
        $this->assertFalse($b->is_active);
        $this->assertSame('directory_deleted', $b->disabled_reason);
        $this->assertSame('Usuário 3 (renomeado)', User::where('entra_oid', $ids['c'])->first()->name);
        $this->assertSame([], $a->fresh()->roles, 'saiu do grupo mapeado: perde o papel');
        $this->assertSame(1, User::where('entra_oid', $ids['c'])->count(), 'sem duplicatas (chave tenant+oid)');

        // conta desativada não autentica mais
        $this->app['auth']->forgetGuards();
        $this->assertTrue(AuditEvent::where('action', 'directory.user_disabled')->where('entity_id', $b->id)->exists());
    }

    public function test_throttling_respects_retry_after(): void
    {
        $this->seedDirectory();
        $this->graph->failures['users/delta'] = [['status' => 429, 'headers' => ['Retry-After' => '7']]];
        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 7);
    }

    public function test_missing_consent_and_invalid_credential_fail_without_advancing_cursor(): void
    {
        $this->seedDirectory();
        $this->graph->failures['groups/delta'] = [['status' => 403, 'body' => ['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges']]]];
        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::FAILED, $run->status);
        $this->assertSame('consent_missing', $run->error_code);
        $this->assertFalse($run->cursor_advanced);
        $this->assertNull(DirectorySyncCursor::where('resource', 'users')->value('delta_link'));
        $this->assertSame(0, User::whereNotNull('directory_synced_at')->count());

        app(EntraAppTokenProvider::class)->forget();
        $this->graph->failures['oauth2/v2.0/token'] = [['status' => 401, 'body' => ['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided.']]];
        $run = $this->sync();
        $this->assertSame('credential_invalid', $run->error_code);
        $this->as('admin')->getJson('/api/v1/admin/settings/entra/directory-sync')->assertJsonPath('status', 'error');
    }

    public function test_expired_delta_token_triggers_controlled_full_reload(): void
    {
        $this->seedDirectory();
        $this->sync();
        $this->graph->failures['groups/delta?$deltatoken'] = [['status' => 410, 'body' => ['error' => ['code' => 'resyncRequired']]]];
        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $this->assertSame(1, $run->totals['delta_reset']);
        $this->assertSame(1, $run->totals['full']);
        $this->assertTrue($run->cursor_advanced);
        $this->assertSame(3, User::whereNotNull('directory_synced_at')->count(), 'recarga idempotente');
    }

    public function test_failure_mid_round_keeps_checkpoint_and_resume_is_idempotent(): void
    {
        $this->seedDirectory();
        // users/delta página 2 falha (erro do Graph): a rodada aborta
        $this->graph->failures['users/delta?$skiptoken=1'] = [['status' => 500]];
        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::FAILED, $run->status);
        $this->assertFalse($run->cursor_advanced);
        $cursor = DirectorySyncCursor::where('resource', 'users')->first();
        $this->assertNull($cursor->delta_link, 'deltaLink só após a rodada inteira');
        $this->assertNotNull($cursor->next_link, 'nextLink guardado para retomada');
        $this->assertSame(0, User::where('is_active', false)->count(), 'falha nunca desativa usuários');

        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $this->assertSame(1, $run->totals['resumed']);
        $this->assertSame(3, User::whereNotNull('directory_synced_at')->count());
        $this->assertNotNull(DirectorySyncCursor::where('resource', 'users')->value('delta_link'));
    }

    public function test_mass_disable_is_blocked_by_safety_threshold(): void
    {
        for ($i = 10; $i < 25; $i++) {
            $this->graph->members[FakeGraph::G1][] = $this->graph->user((string) $i);
        }
        $this->sync();
        $this->assertSame(15, User::where('is_active', true)->whereNotNull('directory_synced_at')->count());
        $this->graph->userChanges = array_map(fn ($u) => ['id' => $u['id'], '@removed' => ['reason' => 'changed']], array_values($this->graph->users));
        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::BLOCKED, $run->status);
        $this->assertSame('removal_threshold', $run->error_code);
        $this->assertFalse($run->cursor_advanced);
        $this->assertSame(15, User::where('is_active', true)->whereNotNull('directory_synced_at')->count(), 'nenhuma desativação em massa');
    }

    public function test_preview_computes_plan_without_persisting_anything(): void
    {
        $this->seedDirectory();
        $run = $this->sync('preview');
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $this->assertSame(3, $run->totals['created']);
        $this->assertNotEmpty($run->plan['changes']);
        $this->assertSame('u*@tmsa.example', collect($run->plan['changes'])->firstWhere('type', 'user')['hint']);
        $this->assertSame(0, User::whereNotNull('directory_synced_at')->count());
        $this->assertSame(0, DirectoryGroup::count());
        $this->assertSame(0, DirectorySyncCursor::count());
        $this->assertFalse($run->cursor_advanced);
    }

    public function test_requests_are_idempotent_while_a_run_is_active(): void
    {
        $first = $this->as('admin')->postJson('/api/v1/admin/entra/sync/run')->assertStatus(202)->json('id');
        $second = $this->as('admin')->postJson('/api/v1/admin/entra/sync/run')->assertStatus(202)->json('id');
        $this->assertSame($first, $second);
    }

    public function test_graph_client_only_follows_links_inside_microsoft_graph(): void
    {
        $client = new HttpGraphClient(fn () => 'token');
        $this->expectException(GraphException::class);
        $client->get('https://evil.example/v1.0/users/delta?$skiptoken=x');
    }

    public function test_connection_test_checks_real_token_permissions(): void
    {
        $this->graph->roles = ['User.Read.All'];
        $this->as('admin')->postJson('/api/v1/admin/settings/entra/directory-sync/test')->assertOk()
            ->assertJsonPath('status', 'error')->assertJsonPath('lastValidation.code', 'consent_missing');
        app(EntraAppTokenProvider::class)->forget();
        $this->graph->roles = ['User.Read.All', 'Group.Read.All'];
        $this->as('admin')->postJson('/api/v1/admin/settings/entra/directory-sync/test')->assertOk()->assertJsonPath('status', 'configured');
        $this->as('admin')->getJson('/api/v1/admin/settings/entra/directory-sync')->assertDontSee('S3gredo-Do-App')->assertJsonPath('value.credential.configured', true);
    }

    public function test_connection_test_explains_delegated_permissions_and_skips_directory_reads(): void
    {
        // permissões cadastradas como Delegated não entram no token app-only: o token só traz as de aplicação
        $this->graph->roles = ['Sites.FullControl.All', 'Chat.Create'];
        $res = $this->as('admin')->postJson('/api/v1/admin/settings/entra/directory-sync/test')->assertOk()
            ->assertJsonPath('status', 'error')->assertJsonPath('lastValidation.code', 'consent_missing');
        $checks = collect($res->json('lastValidation.checks'))->keyBy('name');
        $this->assertStringContainsString('BPM Sync (teste) (cccccccc-0000-4000-8000-000000000001)', $checks['Token app-only (client credentials)']['detail']);
        $this->assertStringContainsString('Sites.FullControl.All, Chat.Create', $checks['Token app-only (client credentials)']['detail']);
        $this->assertStringContainsString('Delegated', $checks['Permissão User.Read.All (Application) com admin consent']['message']);
        $this->assertTrue($checks['App exclusivo do BPM (somente leitura)']['warning']);
        $this->assertFalse($checks['Leituras de teste no diretório']['ok']);
        $this->assertFalse($this->graph->calledWith('/users?'), 'sem permissão não há leitura de teste no diretório');
        $this->assertFalse($this->graph->calledWith('/groups/'));

        $log = $this->as('admin')->getJson('/api/v1/admin/settings/logs/entra')->assertOk()->json('data.0.steps');
        $this->assertSame('warning', collect($log)->firstWhere('code', 'shared_app')['level']);
        $this->assertStringContainsString('Delegated', collect($log)->firstWhere('code', 'consent_missing')['explain']['title']);
    }

    public function test_fictitious_groups_are_refused_with_real_graph_transport(): void
    {
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', ['groups' => [['id' => '6f1e0c1a-0000-4000-8000-0000000000c1']]])
            ->assertStatus(422)->assertJsonPath('errors.groups.0', fn ($m) => str_contains($m, 'diretório fictício'));
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', ['transport' => 'mock', 'groups' => [['id' => '6f1e0c1a-0000-4000-8000-0000000000c1']]])->assertOk();
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', ['transport' => 'graph'])->assertStatus(422);
    }

    public function test_mock_directory_transport_works_for_local_demo(): void
    {
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/directory-sync', ['transport' => 'mock', 'groups' => [['id' => '6f1e0c1a-0000-4000-8000-0000000000c1'], ['id' => '6f1e0c1a-0000-4000-8000-0000000000c2']]])->assertOk();
        $run = $this->sync('full');
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $this->assertSame('mock', $run->transport);
        $this->assertSame(4, $run->totals['created'], '6 fictícios: guest e desabilitado excluídos por padrão');
        $this->assertSame(MockGraphClient::TENANT, User::whereNotNull('directory_synced_at')->value('entra_tid'));
    }

    public function test_user_detail_exposes_entra_integration_groups_grants_and_sync_state_from_the_database(): void
    {
        $ids = $this->seedDirectory();
        $this->as('admin')->postJson('/api/v1/admin/entra/mappings', ['entraGroupId' => FakeGraph::G1, 'entraGroupName' => 'BPM-CQ', 'tmsaGroupKey' => 'CQ', 'tmsaRoles' => ['Bpm.TaskWorker']])->assertCreated();
        $run = $this->sync();
        $this->assertSame(DirectorySyncRun::COMPLETED, $run->status, (string) $run->error_message);
        $calls = count($this->graph->requests);

        $user = User::where('entra_oid', $ids['a'])->firstOrFail();
        $entra = $this->as('admin')->getJson('/api/v1/admin/managed-users/'.$user->id)->assertOk()
            ->assertJsonPath('data.integration.source', 'entra')->assertJsonPath('data.integration.local', null)
            ->assertJsonPath('data.integration.entra.oid', $ids['a'])->assertJsonPath('data.integration.entra.importedBySync', true)
            ->assertJsonPath('data.integration.entra.groups.0.grants.0.groupKey', 'CQ')
            ->assertJsonPath('data.integration.entra.groups.0.grants.0.roles.0', 'Bpm.TaskWorker')
            ->json('data.integration.entra');
        $this->assertNotNull($entra['sync']['lastSuccessAt']);
        $this->assertSame([], $entra['sync']['alerts']);
        $this->assertSame($calls, count($this->graph->requests), 'o painel lê somente o banco: nenhuma chamada ao Graph');

        $this->as('admin')->getJson('/api/v1/admin/managed-users?attention=guest')->assertOk()->assertJsonCount(0, 'data');
        $other = User::where('entra_oid', $ids['b'])->firstOrFail();
        $other->forceFill(['user_type' => 'Guest'])->save();
        $this->as('admin')->getJson('/api/v1/admin/managed-users?attention=guest')->assertOk()->assertJsonPath('data.0.id', $other->id)->assertJsonPath('data.0.userType', 'Guest');
    }
}
