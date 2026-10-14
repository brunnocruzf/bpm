<?php

namespace Tests\Feature;

use App\Engine\ProjectionSync;
use App\Flow\FlowBlueprint as B;
use App\Flow\PublishService;
use App\Forms\FormioBuilder as F;
use App\Mail\MailException;
use App\Models\AuditEvent;
use App\Models\NotificationMessage;
use App\Models\ProcessCatalog;
use App\Models\ProcessInstance;
use App\Models\ProcessStageVersion as S;
use App\Models\TaskProjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeFlowable;
use Tests\Support\FakeMail;
use Tests\TestCase;

class StageNotificationTest extends TestCase
{
    use RefreshDatabase;

    private FakeFlowable $engine;

    private FakeMail $mail;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments_local');
        $this->seedIdentity();
        $this->engine = FakeFlowable::install();
        $this->mail = FakeMail::install();
        $on = ['enabled' => true, 'groupPolicy' => 'members', 'onReassign' => true];
        $catalog = B::create([
            'key' => 'reclamacao', 'name' => 'Reclamação', 'category' => 'Qualidade', 'prefix' => 'REC',
            'stages' => [
                ['key' => 'inicio', 'name' => 'Início', 'type' => S::START],
                ['key' => 'abertura', 'name' => 'Abertura', 'type' => S::USER_TASK, 'lane' => 'CQ', 'config' => ['notifyAssignee' => $on],
                    'form' => ['components' => [F::text('item', 'Item', ['required' => true]), F::yesNo('grave', 'Grave?', ['required' => true])]]],
                ['key' => 'decisao', 'name' => 'É grave?', 'type' => S::EXCLUSIVE_GATEWAY, 'lane' => 'CQ'],
                ['key' => 'gef', 'name' => 'Tratativa GEF', 'type' => S::USER_TASK, 'lane' => 'GEF', 'config' => ['notifyAssignee' => $on], 'form' => ['components' => [F::textarea('obs', 'Obs')]]],
                ['key' => 'sup', 'name' => 'Tratativa Suprimentos', 'type' => S::USER_TASK, 'lane' => 'SUP', 'config' => ['notifyAssignee' => $on], 'form' => ['components' => [F::textarea('obs2', 'Obs')]]],
                ['key' => 'fiscal', 'name' => 'Conferência fiscal', 'type' => S::USER_TASK, 'lane' => 'FISCAL', 'form' => ['components' => [F::textarea('obs3', 'Obs')]]],
                ['key' => 'fim', 'name' => 'Fim', 'type' => S::END],
            ],
            'transitions' => [
                ['inicio', 'abertura'], ['abertura', 'decisao'],
                ['decisao', 'gef', ['when' => B::when(['grave' => 'sim']), 'label' => 'Grave']],
                ['decisao', 'sup', ['default' => true, 'label' => 'Não grave']],
                ['gef', 'fiscal'], ['sup', 'fiscal'], ['fiscal', 'fim'],
            ],
        ], $this->dev('designer'));
        app(PublishService::class)->publish($catalog->latestVersion(), $this->dev('publisher'));
        $this->drainOutbox();
        $this->engine->nextTasksOnStart = ['abertura'];
        // configura o provedor (Mailpit em teste; envio real substituído pelo FakeMail)
        $this->as('admin')->putJson('/api/v1/admin/settings/email', ['provider' => 'mailpit', 'fromAddress' => 'bpm@tmsa.example'])->assertOk();
    }

    private function start(string $grave): string
    {
        return $this->as('cq')->withHeader('Idempotency-Key', 'k-'.Str::random(12))
            ->postJson('/api/v1/process-instances', ['flowKey' => 'reclamacao', 'data' => ['item' => 'Parafuso', 'grave' => $grave]])
            ->assertStatus(202)->json('instance.id');
    }

    public function test_only_the_branch_activated_by_the_engine_is_notified_and_never_before_confirmation(): void
    {
        $this->engine->nextTasksOnComplete = ['gef'];
        $instanceId = $this->start('sim');
        $this->assertSame(0, NotificationMessage::where('event', 'task.assigned')->count(), 'nada antes da confirmação do engine');

        $this->drainOutbox();
        $messages = NotificationMessage::where('event', 'task.assigned')->get();
        $this->assertSame(['user:'.$this->dev('gef')->id], $messages->pluck('recipient')->all(), 'somente o grupo da etapa aberta (GEF); abertura e caminho não escolhido (SUP) não');
        $this->assertSame(TaskProjection::where('stage_key', 'gef')->value('id'), $messages->first()->task_projection_id);
        $this->assertStringContainsString('/tasks/', $messages->first()->body);
        $this->assertStringNotContainsString('Parafuso', $messages->first()->body, 'valores do formulário não vão no e-mail');

        $this->drainNotifications();
        $this->assertSame(['gef@bpm.tmsa.local'], $this->mail->recipients());
        $this->assertTrue(AuditEvent::where('action', 'instance.branch_taken')->where('process_instance_id', $instanceId)->where('data->path', 'Grave')->exists());

        // reprocessar a projeção não duplica a mensagem
        app(ProjectionSync::class)->syncInstance(ProcessInstance::find($instanceId));
        $this->assertSame(1, NotificationMessage::where('event', 'task.assigned')->count());
    }

    public function test_default_branch_notifies_the_other_group_and_toggle_off_stage_sends_nothing(): void
    {
        $this->engine->nextTasksOnComplete = ['sup'];
        $this->start('nao');
        $this->drainOutbox();
        $this->assertSame(['user:'.$this->dev('sup')->id], NotificationMessage::where('event', 'task.assigned')->pluck('recipient')->all());

        // etapa fiscal não habilitou a opção: concluir SUP abre FISCAL sem e-mail
        $this->engine->nextTasksOnComplete = ['fiscal'];
        $task = TaskProjection::where('stage_key', 'sup')->first();
        $this->as('sup')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['obs2' => 'ok']])->assertStatus(202);
        $this->drainOutbox();
        $this->assertTrue(TaskProjection::where('stage_key', 'fiscal')->exists());
        $this->assertSame(1, NotificationMessage::where('event', 'task.assigned')->count());
    }

    public function test_mail_failure_does_not_undo_or_hide_the_task(): void
    {
        $this->engine->nextTasksOnComplete = ['gef'];
        $this->mail->failures = [new MailException('connection_failed', 'Sem conexão.', true)];
        $this->start('sim');
        $this->drainOutbox();
        $this->drainNotifications();
        $this->assertSame(NotificationMessage::RETRYING, NotificationMessage::where('event', 'task.assigned')->first()->state);
        $task = TaskProjection::where('stage_key', 'gef')->first();
        $this->assertSame(TaskProjection::OPEN, $task->status);
        $this->as('gef')->getJson('/api/v1/tasks')->assertOk()->assertJsonFragment(['id' => $task->id]);
    }

    public function test_no_eligible_recipient_is_recorded_as_skipped_without_arbitrary_addresses(): void
    {
        User::where('username', 'gef')->update(['is_active' => false]);
        $this->engine->nextTasksOnComplete = ['gef'];
        $this->start('sim');
        $this->drainOutbox();
        $msg = NotificationMessage::where('event', 'task.assigned')->first();
        $this->assertSame(NotificationMessage::SKIPPED, $msg->state);
        $this->assertNull($msg->recipient_address);
        $this->drainNotifications();
        $this->assertSame([], $this->mail->sent);
    }

    public function test_reassignment_notifies_new_assignee_once(): void
    {
        $this->engine->nextTasksOnComplete = ['gef'];
        $this->start('sim');
        $this->drainOutbox();
        $other = User::create(['username' => 'gef2', 'name' => 'Gilda — GEF', 'email' => 'gef2@bpm.tmsa.local', 'roles' => ['Bpm.TaskWorker'], 'groups' => ['GEF'], 'is_active' => true]);
        $task = TaskProjection::where('stage_key', 'gef')->first();
        $this->assertSame(1, NotificationMessage::where('event', 'task.assigned')->count(), 'criação notificou o membro existente');
        $this->as('gestor')->postJson("/api/v1/tasks/{$task->id}/reassign", ['userId' => $other->id, 'justification' => 'Redistribuição de carga da equipe'])->assertOk();
        $this->as('gestor')->postJson("/api/v1/tasks/{$task->id}/reassign", ['userId' => $other->id, 'justification' => 'Redistribuição de carga da equipe'])->assertOk();
        $keys = NotificationMessage::where('event', 'task.assigned')->pluck('recipient')->all();
        $this->assertSame(1, count(array_keys($keys, 'user:'.$other->id)), 'sem duplicar para o mesmo destinatário/tarefa');
    }

    public function test_template_rejects_unknown_placeholders_and_code(): void
    {
        $v = ProcessCatalog::where('key', 'reclamacao')->first()->latestVersion()->version;
        $this->as('designer')->postJson('/api/v1/definitions/reclamacao/versions')->assertStatus(201);
        $draft = $v + 1;
        $this->as('designer')->patchJson("/api/v1/definitions/reclamacao/versions/$draft/stages/gef", ['config' => ['notifyAssignee' => ['enabled' => true, 'subject' => 'Olá {{cpf}}', 'body' => '{{link}}']]])
            ->assertStatus(422)->assertJsonValidationErrors('notifyAssignee.subject');
        $this->as('designer')->patchJson("/api/v1/definitions/reclamacao/versions/$draft/stages/gef", ['config' => ['notifyAssignee' => ['enabled' => true, 'body' => 'Sem link <?php echo 1; ?>']]])
            ->assertStatus(422);
        $preview = $this->as('designer')->getJson("/api/v1/definitions/reclamacao/versions/$draft/stages/gef/notification-preview")->assertOk();
        $this->assertNull($preview->json('recipients'), 'designer não vê destinatários');
        $this->as('admin')->getJson("/api/v1/definitions/reclamacao/versions/$draft/stages/gef/notification-preview")->assertOk()->assertJsonPath('recipients.0.hint', 'g***f@bpm.tmsa.local');
        $this->as('designer')->postJson("/api/v1/definitions/reclamacao/versions/$draft/stages/gef/notification-test", ['to' => 'x@tmsa.example'])->assertForbidden();
        $this->as('admin')->postJson("/api/v1/definitions/reclamacao/versions/$draft/stages/gef/notification-test", ['to' => 'x@tmsa.example'])->assertStatus(202);
    }
}
