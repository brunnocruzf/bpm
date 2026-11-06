<?php

namespace Tests\Feature;

use App\Engine\ProjectionSync;
use App\Flow\BpmnGenerator;
use App\Flow\BpmnImporter;
use App\Flow\FlowBlueprint as B;
use App\Flow\FlowGraph;
use App\Flow\FlowPresenter;
use App\Flow\FlowValidator;
use App\Flow\PublishService;
use App\Flow\StageService;
use App\Flow\VersionService;
use App\Forms\FormioBuilder as F;
use App\Identity\EmployeeDirectory;
use App\Models\AuditEvent;
use App\Models\EngineCommand;
use App\Models\NotificationMessage;
use App\Models\ProcessCatalog;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessInstance;
use App\Models\ProcessStageVersion as S;
use App\Models\TaskProjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeFlowable;
use Tests\TestCase;

/** Atribuição dinâmica de etapas (ADR-0020): responsável direto, contingência e guardas de execução. */
class TaskAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const FLOW = 'atribuicao_teste';

    private FakeFlowable $engine;

    private function person(string $username, array $attrs = []): User
    {
        return User::create($attrs + [
            'username' => $username, 'name' => Str::title($username), 'email' => "$username@bpm.tmsa.local", 'roles' => ['Bpm.Initiator', 'Bpm.TaskWorker'],
            'groups' => [], 'is_active' => true, 'auth_source' => User::SOURCE_DEMO,
        ]);
    }

    /** @return array<string,mixed> */
    private function spec(array $assignment, array $form = [], array $approval = []): array
    {
        return [
            'key' => self::FLOW, 'name' => 'Atribuição', 'category' => 'Teste', 'prefix' => 'ATR',
            'stages' => [
                ['key' => 'inicio', 'name' => 'Início', 'type' => S::START, 'lane' => 'CQ'],
                ['key' => 'abrir', 'name' => 'Abrir', 'type' => S::USER_TASK, 'lane' => 'CQ', 'form' => ['components' => $form ?: [
                    F::lookup('matricula', 'Matrícula', EmployeeDirectory::SOURCE, ['userId' => 'colaboradorId', 'managerUserId' => 'gestorId'], ['required' => true]),
                    F::checkbox('usarSubstituto', 'Usar substituto'),
                    F::lookup('matriculaSubstituto', 'Substituto', EmployeeDirectory::SOURCE, ['userId' => 'substitutoId'], ['required' => true, 'when' => ['usarSubstituto' => true]]),
                ]]],
                ['key' => 'aprovar', 'name' => 'Aprovar', 'type' => S::USER_TASK, 'lane' => 'SUP',
                    'config' => ['notifyAssignee' => ['enabled' => true, 'groupPolicy' => 'members', 'onReassign' => true], 'assignment' => $assignment],
                    'form' => ['components' => [F::textarea('parecer', 'Parecer', ['required' => true]), ...$approval]]],
                ['key' => 'fim', 'name' => 'Fim', 'type' => S::END],
            ],
            'transitions' => [['inicio', 'abrir'], ['abrir', 'aprovar'], ['aprovar', 'fim']],
        ];
    }

    private function fieldRule(array $overrides = []): array
    {
        return $overrides + ['mode' => 'field', 'fields' => ['substitutoId', 'gestorId'], 'fallbackGroup' => 'CQ', 'excludeRequester' => true, 'excludeFields' => ['colaboradorId']];
    }

    private function draft(array $spec): ProcessDefinitionVersion
    {
        $this->seedIdentity();

        return B::create($spec, $this->dev('designer'))->latestVersion();
    }

    /** Publica o fluxo; a etapa "aprovar" nasce depois da abertura. */
    private function publish(array $spec): ProcessCatalog
    {
        $version = $this->draft($spec);
        $this->engine = FakeFlowable::install();
        app(PublishService::class)->publish($version, $this->dev('publisher'));
        $this->drainOutbox();
        $this->engine->nextTasksOnStart = ['abrir'];
        $this->engine->nextTasksOnComplete = ['aprovar'];

        return $version->catalog->fresh();
    }

    private function start(string $as, array $data)
    {
        return $this->as($as)->withHeader('Idempotency-Key', 'k-'.Str::random(12))
            ->postJson('/api/v1/process-instances', ['flowKey' => self::FLOW, 'data' => $data]);
    }

    /** Colaborador 014085 com gestor imediato, substituto 019000 e o solicitante. */
    private function directory(): array
    {
        $chefe = $this->person('chefe');
        $colaborador = $this->person('colaborador', ['registration_number' => '014085', 'manager_user_id' => $chefe->id]);
        $substituto = $this->person('substituto', ['registration_number' => '019000']);
        $this->person('solicitante');

        return [$chefe, $colaborador, $substituto];
    }

    private function approval(array $data = ['matricula' => '4085']): TaskProjection
    {
        $this->start('solicitante', $data)->assertStatus(202);
        $this->drainOutbox();

        return TaskProjection::where('stage_key', 'aprovar')->sole();
    }

    public function test_the_substitute_wins_over_the_manager_and_only_the_assignee_sees_the_task(): void
    {
        $this->publish($this->spec($this->fieldRule()));
        [$chefe, , $substituto] = $this->directory();
        $this->person('membro', ['groups' => ['SUP']]);

        $task = $this->approval(['matricula' => '4085', 'usarSubstituto' => true, 'matriculaSubstituto' => '9000']);

        $this->assertSame($substituto->id, $task->assignee_id);
        $this->assertNull($task->candidate_group);
        $this->assertSame(TaskProjection::CLAIMED, $task->status);
        $this->assertNotNull($task->claimed_at);

        $this->as('substituto')->getJson('/api/v1/tasks?scope=mine')->assertOk()->assertJsonPath('data.0.id', $task->id)->assertJsonPath('data.0.canRelease', false);
        $this->as('membro')->getJson('/api/v1/tasks?scope=groups')->assertOk()->assertJsonCount(0, 'data');
        $this->as('chefe')->getJson('/api/v1/tasks?scope=mine')->assertOk()->assertJsonCount(0, 'data');
        foreach (['membro', 'chefe'] as $other) {
            $this->as($other)->getJson("/api/v1/tasks/{$task->id}")->assertNotFound();
            $this->as($other)->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['parecer' => 'ok']])->assertNotFound();
        }

        $this->assertSame([$substituto->id], NotificationMessage::where('task_projection_id', $task->id)->pluck('recipient_user_id')->all(), 'e-mail só ao responsável');
        $this->assertNotSame($chefe->id, $task->assignee_id);

        $event = AuditEvent::where('action', 'task.auto_assigned')->sole();
        $this->assertSame($substituto->id, $event->data['to']);
        $this->assertSame('substitutoId', $event->data['field']);
    }

    public function test_without_a_substitute_the_manager_is_assigned_and_can_complete(): void
    {
        $this->publish($this->spec($this->fieldRule()));
        [$chefe] = $this->directory();

        $task = $this->approval();

        $this->assertSame($chefe->id, $task->assignee_id);
        $this->as('chefe')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['parecer' => 'ok']])->assertStatus(202);
    }

    public function test_references_reach_the_instance_but_not_the_payload_the_engine_or_the_browser(): void
    {
        $this->publish($this->spec($this->fieldRule()));
        [$chefe, $colaborador] = $this->directory();

        // o navegador não consegue informar a referência
        $this->approval(['matricula' => '4085', 'gestorId' => (string) Str::uuid(), 'colaboradorId' => (string) Str::uuid()]);

        $instance = ProcessInstance::sole();
        $this->assertSame($chefe->id, $instance->data['gestorId']);
        $this->assertSame($colaborador->id, $instance->data['colaboradorId']);
        $this->assertNull($instance->data['substitutoId'], 'consulta oculta não preenche a referência');

        $this->assertArrayNotHasKey('gestorId', EngineCommand::where('command', EngineCommand::START)->sole()->payload['variables']);
        $submission = $instance->submissions()->sole();
        $this->assertArrayNotHasKey('gestorId', $submission->payload);
        $this->assertStringNotContainsString($chefe->id, (string) json_encode($submission->metadata), 'os metadados são devolvidos em Resultados');

        $this->as('solicitante')->getJson('/api/v1/lookups/employee?value=4085')->assertOk()
            ->assertJsonPath('data.name', 'Colaborador')->assertJsonMissingPath('data.userId')->assertJsonMissingPath('data.managerUserId');
    }

    /** @return array<string, array{0: callable(User): mixed, 1: string}> */
    public static function unavailable(): array
    {
        return [
            'removido' => [fn (User $u) => $u->delete(), 'user_not_found'],
            'inativo' => [fn (User $u) => $u->update(['is_active' => false]), 'user_inactive'],
            'conta desabilitada' => [fn (User $u) => $u->update(['account_enabled' => false]), 'user_inactive'],
            'sem papel' => [fn (User $u) => $u->update(['roles' => ['Bpm.Initiator']]), 'user_without_role'],
        ];
    }

    #[DataProvider('unavailable')]
    public function test_a_person_who_became_unavailable_falls_back_to_the_stage_group(callable $change, string $reason): void
    {
        $this->publish($this->spec($this->fieldRule()));
        [$chefe, $colaborador] = $this->directory();
        $membro = $this->person('membro', ['groups' => ['CQ']]);
        $this->engine->nextTasksOnComplete = [];
        $this->start('solicitante', ['matricula' => '4085'])->assertStatus(202);
        $this->drainOutbox();

        // entre o envio e a criação da tarefa o aprovador deixou de poder executar
        $colaborador->update(['manager_user_id' => null]);
        $change($chefe);
        $instance = ProcessInstance::sole();
        $this->engine->createTask((string) $instance->engine_process_instance_id, 'aprovar');
        app(ProjectionSync::class)->syncInstance($instance);

        $task = TaskProjection::where('stage_key', 'aprovar')->sole();
        $this->assertNull($task->assignee_id);
        $this->assertSame('CQ', $task->candidate_group);
        $this->assertSame(TaskProjection::OPEN, $task->status);
        $this->assertSame($reason, AuditEvent::where('action', 'task.assignment_fallback')->sole()->data['reason']);
        $this->as('membro')->postJson("/api/v1/tasks/{$task->id}/claim")->assertOk();
        $this->assertSame($membro->id, $task->fresh()->assignee_id);
    }

    public function test_a_reference_that_is_not_a_user_id_falls_back_without_breaking_the_sync(): void
    {
        $this->publish($this->spec($this->fieldRule()));
        $this->directory();
        $this->engine->nextTasksOnComplete = [];
        $this->start('solicitante', ['matricula' => '4085'])->assertStatus(202);
        $this->drainOutbox();

        $instance = ProcessInstance::sole();
        $instance->update(['data' => ['gestorId' => 'não é um uuid']]);
        $this->engine->createTask((string) $instance->engine_process_instance_id, 'aprovar');
        app(ProjectionSync::class)->syncInstance($instance);

        $this->assertSame('CQ', TaskProjection::where('stage_key', 'aprovar')->sole()->candidate_group);
        $this->assertSame('invalid_reference', AuditEvent::where('action', 'task.assignment_fallback')->sole()->data['reason']);
    }

    public function test_the_requester_manager_mode_never_assigns_the_requester_when_excluded(): void
    {
        $this->publish($this->spec(['mode' => 'requester_manager', 'fallbackGroup' => 'CQ', 'excludeRequester' => true], [F::text('observacao', 'Observação')]));
        $solicitante = $this->person('solicitante');
        // dado inconsistente do diretório: o gestor do solicitante é ele mesmo
        $solicitante->update(['manager_user_id' => $solicitante->id]);

        $task = $this->approval(['observacao' => 'x']);

        $this->assertNull($task->assignee_id);
        $this->assertSame('CQ', $task->candidate_group);
        $this->assertSame('is_requester', AuditEvent::where('action', 'task.assignment_fallback')->sole()->data['reason']);
    }

    public function test_the_requester_manager_mode_assigns_the_manager(): void
    {
        $this->publish($this->spec(['mode' => 'requester_manager', 'fallbackGroup' => 'CQ'], [F::text('observacao', 'Observação')]));
        $chefe = $this->person('chefe');
        $this->person('solicitante', ['manager_user_id' => $chefe->id]);

        $this->assertSame($chefe->id, $this->approval(['observacao' => 'x'])->assignee_id);
    }

    public function test_the_requester_mode_returns_the_task_to_the_requester(): void
    {
        $this->publish($this->spec(['mode' => 'requester', 'fallbackGroup' => 'CQ'], [F::text('observacao', 'Observação')]));
        $solicitante = $this->person('solicitante');

        $task = $this->approval(['observacao' => 'x']);

        $this->assertSame($solicitante->id, $task->assignee_id);
        $this->assertNull($task->candidate_group);
    }

    public function test_a_requester_without_the_worker_role_falls_back(): void
    {
        $this->publish($this->spec(['mode' => 'requester', 'fallbackGroup' => 'CQ'], [F::text('observacao', 'Observação')]));
        $this->person('solicitante', ['roles' => ['Bpm.Initiator']]);

        $task = $this->approval(['observacao' => 'x']);

        $this->assertNull($task->assignee_id);
        $this->assertSame('CQ', $task->candidate_group);
    }

    public function test_a_directly_assigned_task_cannot_be_released_but_can_be_reassigned(): void
    {
        $this->publish($this->spec($this->fieldRule()));
        [, $colaborador, $substituto] = $this->directory();
        $task = $this->approval();

        $this->as('chefe')->postJson("/api/v1/tasks/{$task->id}/release")->assertStatus(409)->assertJsonPath('code', 'task_directly_assigned');
        $this->as('admin')->getJson("/api/v1/tasks/{$task->id}")->assertOk()->assertJsonPath('canRelease', false)->assertJsonPath('canReassign', true);
        $this->as('chefe')->getJson("/api/v1/tasks/{$task->id}")->assertOk()->assertJsonPath('canReassign', false);

        $reassign = fn (string $userId) => $this->as('admin')->postJson("/api/v1/tasks/{$task->id}/reassign", ['userId' => $userId, 'justification' => 'Gestor em férias até o fim do mês']);
        $reassign($colaborador->id)->assertStatus(422)->assertJsonValidationErrors(['userId'], 'errors');
        $reassign((string) User::where('username', 'solicitante')->value('id'))->assertStatus(422)->assertJsonValidationErrors(['userId'], 'errors');
        $reassign((string) Str::uuid())->assertStatus(422)->assertJsonValidationErrors(['userId'], 'errors');
        $reassign($substituto->id)->assertOk();

        $this->assertSame($substituto->id, $task->fresh()->assignee_id);
        $this->assertNull($task->fresh()->candidate_group);
        $this->assertSame($substituto->id, AuditEvent::where('action', 'task.reassigned')->sole()->data['to']);
    }

    public function test_a_stage_without_a_rule_keeps_the_group_behaviour(): void
    {
        $spec = $this->spec($this->fieldRule());
        unset($spec['stages'][2]['config']['assignment']);
        $this->publish($spec);
        $this->directory();

        $task = $this->approval();

        $this->assertSame('SUP', $task->candidate_group);
        $this->assertNull($task->assignee_id);
        $this->assertSame(TaskProjection::OPEN, $task->status);
        $this->assertFalse(AuditEvent::whereIn('action', ['task.auto_assigned', 'task.assignment_fallback'])->exists());
    }

    public function test_the_validator_refuses_invalid_rules(): void
    {
        $version = $this->draft($this->spec($this->fieldRule(), [], [
            F::lookup('revisor', 'Revisor', EmployeeDirectory::SOURCE, ['userId' => 'revisorId']),
        ]));
        $codes = function (array $rule, string $stage = 'aprovar') use ($version) {
            $version->stages()->where('stage_key', 'aprovar')->sole()->update(['config' => []]);
            $version->stages()->where('stage_key', $stage)->sole()->update(['config' => ['assignment' => $rule]]);

            return array_column(app(FlowValidator::class)->validate(FlowGraph::load($version->fresh())), 'code');
        };

        $this->assertNotContains('assignment_field_type', $codes($this->fieldRule()));
        $this->assertContains('assignment_mode', $codes(['mode' => 'robo']));
        $this->assertContains('assignment_field_type', $codes($this->fieldRule(['fields' => ['parecer']])), 'campo digitado não define responsável');
        $this->assertContains('assignment_fields', $codes($this->fieldRule(['fields' => []])));
        $this->assertContains('assignment_field_order', $codes($this->fieldRule(['fields' => ['revisorId']])), 'preenchida na própria etapa');
        $this->assertContains('assignment_exclude_field', $codes($this->fieldRule(['excludeFields' => ['parecer']])));
        $this->assertContains('assignment_unless_field', $codes($this->fieldRule(['requiredUnless' => ['naoExiste' => 'sim']])));
        $this->assertContains('assignment_fallback', $codes(['mode' => 'requester']));
        $this->assertContains('assignment_fallback', $codes(['mode' => 'requester', 'fallbackGroup' => 'NAO_EXISTE']));
        $this->assertContains('assignment_opening', $codes(['mode' => 'requester', 'fallbackGroup' => 'CQ'], 'abrir'));
    }

    public function test_a_stage_with_a_rule_does_not_need_a_lane(): void
    {
        $version = $this->draft($this->spec($this->fieldRule()));
        $version->stages()->where('stage_key', 'aprovar')->sole()->update(['lane' => null]);

        $this->assertNotContains('assignee_missing', array_column(app(FlowValidator::class)->validate(FlowGraph::load($version->fresh())), 'code'));
    }

    public function test_the_rule_survives_saving_a_new_version_and_the_bpmn_round_trip(): void
    {
        $rule = $this->fieldRule(['requiredUnless' => ['usarSubstituto' => true]]);
        $catalog = $this->publish($this->spec($rule));
        $designer = $this->dev('designer');

        $this->assertStringContainsString('<tmsa:assignment>', (string) $catalog->latestVersion()->bpmn_xml);
        $this->assertStringContainsString('flowable:candidateGroups="SUP"', (string) $catalog->latestVersion()->bpmn_xml);

        $draft = app(VersionService::class)->createDraft($catalog, $designer);
        $stage = fn () => $draft->stages()->where('stage_key', 'aprovar')->sole();
        $this->assertEquals($rule, $stage()->configValue('assignment'), 'nova versão');

        app(StageService::class)->update($stage(), ['config' => [...$stage()->config, 'slaDays' => 3]], $designer);
        $this->assertEquals($rule, $stage()->configValue('assignment'), 'salvar a etapa');
        $this->assertSame(3, $stage()->configValue('slaDays'));

        app(BpmnImporter::class)->import($draft->fresh(), app(BpmnGenerator::class)->generate(FlowGraph::load($draft->fresh())), $designer);
        $this->assertEquals($rule, $stage()->configValue('assignment'), 'reimportar o BPMN');

        $keys = array_column(FlowPresenter::version($draft->fresh())['userFields'], 'key');
        $this->assertEqualsCanonicalizing(['colaboradorId', 'gestorId', 'substitutoId'], $keys);
    }

    public function test_saving_or_importing_an_unknown_mode_is_refused(): void
    {
        $version = $this->draft($this->spec($this->fieldRule()));
        $base = '/api/v1/definitions/'.self::FLOW.'/versions/1';

        $this->as('designer')->patchJson("$base/stages/aprovar", ['config' => ['assignment' => ['mode' => 'robo']]])->assertStatus(422);

        $xml = app(BpmnGenerator::class)->generate(FlowGraph::load($version));
        $this->as('designer')->postJson("$base/bpmn/import", ['xml' => str_replace('"mode":"field"', '"mode":"robo"', $xml)])->assertStatus(422);
        $this->as('designer')->postJson("$base/bpmn/import", ['xml' => str_replace('"mode":"field"', 'quebrado', $xml)])->assertStatus(422);
    }

    public function test_the_notification_preview_points_to_the_contingency_group(): void
    {
        $this->draft($this->spec($this->fieldRule()));

        $this->as('admin')->getJson('/api/v1/definitions/'.self::FLOW.'/versions/1/stages/aprovar/notification-preview')->assertOk()
            ->assertJsonPath('assignment.mode', 'field')->assertJsonPath('assignment.fallbackGroup', 'CQ')->assertJsonPath('recipients.0.name', 'Carla — CQ Recebimento');
    }
}
