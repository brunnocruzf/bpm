<?php

namespace Tests\Feature;

use App\Flow\FlowGraph;
use App\Flow\FlowValidator;
use App\Models\BpmGroup;
use App\Models\EngineCommand;
use App\Models\IntegrationBinding;
use App\Models\ProcessCatalog;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessInstance;
use App\Models\TaskProjection;
use App\Models\User;
use Database\Seeders\Blueprints\For784;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeFlowable;
use Tests\TestCase;

/** FOR-784 — Ocorrência de Ponto: instalação por migration e regras dos formulários. */
class For784FlowTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_04_000100_install_for784_ocorrencia_ponto.php';

    private FakeFlowable $engine;

    private function migrate(): void
    {
        (require database_path(self::MIGRATION))->up();
    }

    private function install(): ProcessCatalog
    {
        $this->seedIdentity();
        $this->engine = FakeFlowable::install();
        $this->migrate();
        $this->drainOutbox();

        return ProcessCatalog::where('key', For784::KEY)->sole();
    }

    /** @param list<string> $groups */
    private function worker(string $username, array $groups): void
    {
        User::create(['username' => $username, 'name' => Str::title($username), 'email' => "$username@bpm.tmsa.local", 'roles' => ['Bpm.Initiator', 'Bpm.TaskWorker'],
            'groups' => $groups, 'is_active' => true, 'auth_source' => User::SOURCE_DEMO]);
    }

    /** Colaboradores sincronizados do Entra: matrícula com o código da empresa (01 TMSA, 02 IMS) e gestor. */
    private function directory(): void
    {
        $person = fn (string $name, ?string $registration, ?User $manager = null, bool $active = true) => User::create([
            'name' => $name, 'email' => Str::slug($name).'@tmsa.example', 'registration_number' => $registration, 'manager_user_id' => $manager?->id,
            'is_active' => $active, 'auth_source' => User::SOURCE_ENTRA, 'roles' => [], 'groups' => [],
        ]);
        $lenoir = $person('Lenoir Jaques Schneider', '010017');
        $person('Colaborador TMSA', '014085', $lenoir);
        $person('Colaborador antigo', '0104086', null, false);
        $person('Colaborador recontratado', '014086', $lenoir);
        $person('Ana TMSA', '017777', $lenoir);
        $person('Bia IMS', '027777');
    }

    private function opening(array $overrides = []): array
    {
        return $overrides + [
            'matriculaColaborador' => '4085',
            'nomeColaborador' => 'digitado no navegador (ignorado)',
            'gestorImediato' => 'digitado no navegador (ignorado)',
            'substituirGestor' => false,
            'solicitanteGestor' => 'nao',
            'ocorrencias' => [['motivo' => 'esquecimento_registro', 'dataOcorrencia' => '2026-11-03T00:00:00-03:00', 'entrada' => '22:00', 'saida' => '06:00', 'noturno' => true]],
        ];
    }

    public function test_migration_does_nothing_on_a_base_without_users(): void
    {
        // RefreshDatabase já executou todas as migrations numa base vazia (instalação nova / testes).
        $this->migrate();

        $this->assertFalse(ProcessCatalog::where('key', For784::KEY)->exists());
        $this->assertFalse(BpmGroup::where('key', 'PONTO_GESTOR')->exists());
        $this->assertFalse(IntegrationBinding::where('operation', For784::INTEGRATION)->exists());
    }

    public function test_migration_installs_the_published_flow_once(): void
    {
        $catalog = $this->install();
        $this->migrate();

        $this->assertSame('FOR-784 - Ocorrência de Ponto', $catalog->name);
        $this->assertSame('OCP', $catalog->instance_prefix);
        $this->assertSame(1, ProcessCatalog::where('key', For784::KEY)->count());
        $this->assertSame(1, $catalog->versions()->count());
        $version = $catalog->latestVersion();
        $this->assertSame(ProcessDefinitionVersion::STATUS_PUBLISHED, $version->status);
        $this->assertSame(EngineCommand::CONFIRMED, EngineCommand::where('definition_version_id', $version->id)->where('command', EngineCommand::DEPLOY)->sole()->state);
        foreach (array_keys(For784::GROUPS) as $group) {
            $this->assertTrue(BpmGroup::where('key', $group)->exists(), $group);
        }

        $binding = IntegrationBinding::where('operation', For784::INTEGRATION)->sole();
        $this->assertTrue($binding->custom);
        $this->assertFalse($binding->enabled);
        $this->assertSame(['statusIntegracao', 'mensagemIntegracao'], array_keys($binding->outputsMap()));

        $problems = app(FlowValidator::class)->validate(FlowGraph::load($version));
        $this->assertFalse(FlowValidator::hasErrors($problems), json_encode($problems, JSON_UNESCAPED_UNICODE));
        $this->assertContains('service_operation_inactive', array_column($problems, 'code'));

        $xml = (string) $version->bpmn_xml;
        $this->assertStringContainsString('<timeDuration>P2D</timeDuration>', $xml);
        $this->assertStringContainsString('flowable:candidateGroups="PONTO_GESTOR"', $xml);
        $this->assertStringContainsString('flowable:candidateGroups="RH"', $xml);
        $this->assertSame(3, substr_count($xml, '<terminateEventDefinition'), 'Cancelado, Reprovado e Fim encerram também o timer do gestor');
    }

    public function test_update_migration_publishes_a_new_version_only_when_the_blueprint_changed(): void
    {
        $catalog = $this->install();
        $update = fn () => (require database_path('migrations/2026_11_04_000300_update_for784_ocorrencia_ponto.php'))->up();
        $update();
        $this->assertSame(1, $catalog->versions()->count(), 'blueprint igual à versão instalada: nada muda');

        // simula a base instalada com o formulário antigo da abertura
        $v1 = $catalog->latestVersion();
        $v1->forms()->whereHas('stage', fn ($q) => $q->where('stage_key', 'registrar_marcacoes'))->update(['hash' => 'formulario-antigo']);
        $update();
        $this->drainOutbox();

        $catalog->refresh();
        $this->assertSame(2, $catalog->versions()->count());
        $v2 = $catalog->latestVersion();
        $this->assertSame(2, $v2->version);
        $this->assertSame(ProcessDefinitionVersion::STATUS_PUBLISHED, $v2->status);
        $this->assertSame($v2->id, $catalog->current_published_version_id);
        $this->assertSame(ProcessDefinitionVersion::STATUS_RETIRED, $v1->fresh()->status);
        $this->assertStringContainsString('"key":"noturno"', json_encode($v2->forms()->get()->pluck('schema')));

        $update();
        $this->assertSame(2, $catalog->versions()->count(), 'já atualizado: idempotente');
    }

    public function test_opening_reaches_the_manager_and_correction_requires_a_reason(): void
    {
        $this->install();
        $this->directory();
        $this->worker('solicitante', ['PONTO_SOLICITANTE']);
        $this->worker('gestorponto', ['PONTO_GESTOR']);
        $this->engine->nextTasksOnStart = ['registrar_marcacoes'];
        $this->engine->nextTasksOnComplete = ['aprovar_gestor'];

        $start = fn (array $data) => $this->as('solicitante')->withHeader('Idempotency-Key', 'k-'.Str::random(12))
            ->postJson('/api/v1/process-instances', ['flowKey' => For784::KEY, 'data' => $data]);
        $start($this->opening(['ocorrencias' => []]))->assertStatus(422);
        $start($this->opening(['ocorrencias' => [['motivo' => 'outro', 'dataOcorrencia' => '2026-11-03T00:00:00-03:00']]]))
            ->assertStatus(422)->assertJsonValidationErrors(['ocorrencias[0].motivo'], 'errors');
        $start($this->opening(['ocorrencias' => [['motivo' => 'problema_relogio', 'dataOcorrencia' => '2026-11-03T00:00:00-03:00', 'entrada' => '25:00', 'saida' => '8h']]]))
            ->assertStatus(422)->assertJsonValidationErrors(['ocorrencias[0].entrada', 'ocorrencias[0].saida'], 'errors');
        $start($this->opening(['substituirGestor' => true]))->assertStatus(422)->assertJsonValidationErrors(['gestorSubstituto'], 'errors');
        $start($this->opening(['matriculaColaborador' => '9999']))->assertStatus(422)->assertJsonValidationErrors(['matriculaColaborador'], 'errors');
        $start($this->opening(['matriculaColaborador' => '7777']))->assertStatus(422)->assertJsonValidationErrors(['matriculaColaborador'], 'errors');
        $start($this->opening())->assertStatus(202);
        $this->drainOutbox();

        $instance = ProcessInstance::sole();
        $this->assertStringStartsWith('OCP-', $instance->number);
        $this->assertSame('Solicitante', $instance->data['criadoPor']);
        $this->assertSame($instance->number, $instance->data['numeroSolicitacao']);
        $this->assertSame('Colaborador TMSA', $instance->data['nomeColaborador'], 'Preenchido pelo servidor a partir da matrícula');
        $this->assertSame('Lenoir Jaques Schneider', $instance->data['gestorImediato']);
        $this->assertSame('lenoir-jaques-schneider@tmsa.example', $instance->data['gestorImediatoEmail']);

        $task = TaskProjection::where('stage_key', 'aprovar_gestor')->sole();
        $this->assertSame('PONTO_GESTOR', $task->candidate_group);
        $this->as('solicitante')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['decisaoGestor' => 'aprovado']])->assertForbidden();
        $this->as('gestorponto')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['decisaoGestor' => 'correcao']])
            ->assertStatus(422)->assertJsonValidationErrors(['motivoCorrecao'], 'errors');
        $this->as('gestorponto')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['decisaoGestor' => 'correcao', 'motivoCorrecao' => 'Faltou a 2ª saída']])
            ->assertStatus(202);
    }

    public function test_employee_lookup_ignores_the_company_code_of_the_registration_number(): void
    {
        $this->seedIdentity();
        $this->directory();
        $lookup = fn (string $value) => $this->as('cq')->getJson('/api/v1/lookups/employee?value='.$value);

        foreach (['4085', '004085', '014085'] as $value) {
            $lookup($value)->assertOk()->assertJsonPath('found', true)->assertJsonPath('transport', 'BPM:usuarios')
                ->assertJsonPath('data.name', 'Colaborador TMSA')->assertJsonPath('data.company', 'TMSA')
                ->assertJsonPath('data.managerName', 'Lenoir Jaques Schneider');
        }
        // conta antiga desativada com o mesmo número: vale a ativa
        $lookup('4086')->assertOk()->assertJsonPath('data.name', 'Colaborador recontratado');
        // mesmo número em duas empresas: pede a matrícula completa
        $lookup('7777')->assertStatus(422)->assertJsonFragment(['value' => ['A matrícula "7777" corresponde a mais de um colaborador (017777 (TMSA), 027777 (IMS)). Digite a matrícula completa, com o código da empresa (01 TMSA, 02 IMS).']]);
        $lookup('027777')->assertOk()->assertJsonPath('data.name', 'Bia IMS')->assertJsonPath('data.company', 'IMS')->assertJsonPath('data.managerName', null);
        $lookup('9999')->assertOk()->assertJsonPath('found', false);
        $lookup('0')->assertOk()->assertJsonPath('found', false);
    }
}
