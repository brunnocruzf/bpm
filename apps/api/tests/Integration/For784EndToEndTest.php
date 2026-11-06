<?php

namespace Tests\Integration;

use App\Engine\ProjectionSync;
use App\Models\ProcessCatalog;
use App\Models\ProcessInstance;
use App\Models\TaskProjection;
use App\Models\User;
use Database\Seeders\Blueprints\For784;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * FOR-784 contra um Flowable REST REAL: o engine aceita tmsa:assignment, roteia pela variável calculada no
 * servidor e cada entrada na etapa resolve o responsável de novo (ADR-0020).
 * Executar com: FLOWABLE_IT_URL=http://127.0.0.1:8090/flowable-rest FLOWABLE_IT_USER=... FLOWABLE_IT_PASSWORD=... php artisan test --testsuite=Integration
 */
#[Group('flowable')]
class For784EndToEndTest extends TestCase
{
    use RefreshDatabase;

    private string $instanceId;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('FLOWABLE_IT_URL')) {
            $this->markTestSkipped('Defina FLOWABLE_IT_URL para rodar contra um Flowable REST real.');
        }
        config([
            'bpm.engine.base_url' => rtrim((string) getenv('FLOWABLE_IT_URL'), '/'),
            'bpm.engine.username' => getenv('FLOWABLE_IT_USER'),
            'bpm.engine.password' => getenv('FLOWABLE_IT_PASSWORD'),
        ]);
        $this->seedIdentity();
        For784::install();
        $this->drainOutbox();
        $this->assertNotNull(ProcessCatalog::where('key', For784::KEY)->sole()->publishedVersion->engine_definition_id, 'Deploy não confirmado');

        $person = fn (string $username, ?string $registration, ?User $manager = null) => User::create([
            'username' => $username, 'name' => Str::title($username), 'email' => "$username@bpm.tmsa.local", 'registration_number' => $registration,
            'manager_user_id' => $manager?->id, 'roles' => ['Bpm.Initiator', 'Bpm.TaskWorker'], 'groups' => [], 'is_active' => true, 'auth_source' => User::SOURCE_DEMO,
        ]);
        $chefe = $person('chefe', '010017');
        $person('colaborador', '014085', $chefe);
        $person('substituto', '015003');
        $person('solicitante', null);
    }

    private function open(string $as, array $overrides = []): void
    {
        $response = $this->as($as)->withHeader('Idempotency-Key', 'it-start-'.Str::random(10))->postJson('/api/v1/process-instances', ['flowKey' => For784::KEY, 'data' => $overrides + [
            'matriculaColaborador' => '4085',
            'substituirGestor' => false,
            'ocorrencias' => [['motivo' => 'esquecimento_registro', 'dataOcorrencia' => '2026-11-03T00:00:00-03:00', 'entrada' => '08:00', 'saida' => '17:00']],
        ]]);
        $response->assertStatus(202);
        $this->instanceId = $response->json('instance.id');
        $this->drainOutbox();
    }

    private function task(string $stageKey): TaskProjection
    {
        $task = TaskProjection::where('process_instance_id', $this->instanceId)->where('stage_key', $stageKey)
            ->whereIn('status', [TaskProjection::OPEN, TaskProjection::CLAIMED])->latest('created_at')->first();
        $this->assertNotNull($task, "Tarefa $stageKey não está aberta. Abertas: ".TaskProjection::where('process_instance_id', $this->instanceId)->whereIn('status', TaskProjection::ACTIVE)->pluck('stage_key')->implode(', '));

        return $task;
    }

    private function complete(string $user, string $stageKey, array $data): void
    {
        $task = $this->task($stageKey);
        $response = $this->as($user)->withHeader('Idempotency-Key', 'it-'.Str::random(12))->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => $data, 'rowVersion' => $task->row_version]);
        $this->assertSame(202, $response->status(), "Falha ao concluir $stageKey: ".$response->getContent());
        $this->drainOutbox();
        $this->assertSame(TaskProjection::COMPLETED, $task->fresh()->status, "$stageKey não confirmada pelo engine");
    }

    private function assertEnded(string $end, string $status, string $result): void
    {
        $instance = ProcessInstance::find($this->instanceId);
        $this->assertSame($end, $instance->end_stage_key);
        $this->assertSame($status, $instance->status);
        $this->assertSame($result, $instance->result);
    }

    private function userId(string $username): string
    {
        return (string) User::where('username', $username)->value('id');
    }

    public function test_the_approval_goes_to_the_manager_and_the_correction_back_to_the_requester(): void
    {
        $this->open('solicitante');

        $approval = $this->task('aprovar_gestor');
        $this->assertSame($this->userId('chefe'), $approval->assignee_id);
        $this->assertNull($approval->candidate_group);

        $this->complete('chefe', 'aprovar_gestor', ['decisaoGestor' => 'correcao', 'motivoCorrecao' => 'Faltou a saída']);
        $correction = $this->task('corrigir_marcacoes');
        $this->assertSame($this->userId('solicitante'), $correction->assignee_id);

        $this->complete('solicitante', 'corrigir_marcacoes', ['acaoCorrecao' => 'reenviar', 'ocorrencias' => [['motivo' => 'problema_relogio', 'dataOcorrencia' => '2026-11-03T00:00:00-03:00']]]);
        $this->assertSame($this->userId('chefe'), $this->task('aprovar_gestor')->assignee_id, 'o reenvio resolve o responsável de novo');

        $this->complete('chefe', 'aprovar_gestor', ['decisaoGestor' => 'reprovado', 'motivoReprovacao' => 'Sem justificativa']);
        $this->assertEnded('fim_reprovado', ProcessInstance::COMPLETED, 'reprovado');
    }

    public function test_the_requester_giving_up_stays_cancelled(): void
    {
        $this->open('solicitante');
        $this->complete('chefe', 'aprovar_gestor', ['decisaoGestor' => 'correcao', 'motivoCorrecao' => 'Faltou a saída']);
        $this->complete('solicitante', 'corrigir_marcacoes', ['acaoCorrecao' => 'cancelar', 'ocorrencias' => [['motivo' => 'problema_relogio', 'dataOcorrencia' => '2026-11-03T00:00:00-03:00']]]);

        $this->assertEnded('fim_cancelado', ProcessInstance::CANCELLED, 'cancelado');
    }

    public function test_an_instance_deleted_in_the_engine_is_cancelled(): void
    {
        $this->open('solicitante');
        $instance = ProcessInstance::find($this->instanceId);

        Http::withBasicAuth((string) getenv('FLOWABLE_IT_USER'), (string) getenv('FLOWABLE_IT_PASSWORD'))
            ->delete(rtrim((string) getenv('FLOWABLE_IT_URL'), '/').'/service/runtime/process-instances/'.$instance->engine_process_instance_id.'?deleteReason=suporte')
            ->throw();
        app(ProjectionSync::class)->syncInstance($instance);

        $instance->refresh();
        $this->assertSame(ProcessInstance::CANCELLED, $instance->status);
        $this->assertSame('cancelado', $instance->result);
    }

    public function test_the_substitute_approves_and_the_flow_reaches_hr(): void
    {
        $this->open('solicitante', ['substituirGestor' => true, 'matriculaSubstituto' => '5003']);

        $this->assertSame($this->userId('substituto'), $this->task('aprovar_gestor')->assignee_id);
        $this->complete('substituto', 'aprovar_gestor', ['decisaoGestor' => 'aprovado']);
        $this->assertSame('RH', $this->task('aprovar_rh')->candidate_group);
    }

    public function test_the_engine_routes_the_actual_manager_straight_to_hr(): void
    {
        $this->open('chefe');

        $this->assertSame('RH', $this->task('aprovar_rh')->candidate_group);
        $this->assertFalse(TaskProjection::where('process_instance_id', $this->instanceId)->where('stage_key', 'aprovar_gestor')->exists());
    }
}
