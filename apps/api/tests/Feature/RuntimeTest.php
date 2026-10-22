<?php

namespace Tests\Feature;

use App\Flow\FlowBlueprint;
use App\Flow\PublishService;
use App\Forms\FormioBuilder as F;
use App\Models\AuditEvent;
use App\Models\EngineCommand;
use App\Models\FormSubmission;
use App\Models\ProcessInstance;
use App\Models\ProcessStageVersion as S;
use App\Models\TaskProjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeFlowable;
use Tests\TestCase;

class RuntimeTest extends TestCase
{
    use RefreshDatabase;

    private FakeFlowable $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments_local');
        $this->seedIdentity();
        $this->engine = FakeFlowable::install();
        $catalog = FlowBlueprint::create([
            'key' => 'compra', 'name' => 'Compra', 'category' => 'Compras', 'prefix' => 'CMP',
            'stages' => [
                ['key' => 'inicio', 'name' => 'Início', 'type' => S::START],
                ['key' => 'abertura', 'name' => 'Abertura', 'type' => S::USER_TASK, 'lane' => 'CQ', 'form' => ['components' => [F::text('item', 'Item', ['required' => true]), F::file('anexo', 'Anexo')]]],
                ['key' => 'analise', 'name' => 'Análise', 'type' => S::USER_TASK, 'lane' => 'GEF', 'form' => ['components' => [F::copied(F::text('item', 'Item')), F::yesNo('ok', 'OK?', ['required' => true])]]],
                ['key' => 'fim', 'name' => 'Fim', 'type' => S::END],
            ],
            'transitions' => [['inicio', 'abertura'], ['abertura', 'analise'], ['analise', 'fim']],
        ], $this->dev('designer'));
        app(PublishService::class)->publish($catalog->latestVersion(), $this->dev('publisher'));
        $this->drainOutbox();
        $this->engine->nextTasksOnStart = ['abertura'];
    }

    private function start(?string $key = null, array $data = ['item' => 'Parafuso']): TestResponse
    {
        return $this->as('cq')->withHeader('Idempotency-Key', $key ?? 'k-'.Str::random(12))->postJson('/api/v1/process-instances', ['flowKey' => 'compra', 'data' => $data]);
    }

    private function startAndReachAnalysis(): ProcessInstance
    {
        $this->engine->nextTasksOnComplete = ['analise'];
        $id = $this->start()->assertStatus(202)->json('instance.id');
        $this->drainOutbox();

        return ProcessInstance::find($id);
    }

    public function test_start_requires_idempotency_key_and_replays_same_response(): void
    {
        $this->as('cq')->postJson('/api/v1/process-instances', ['flowKey' => 'compra', 'data' => ['item' => 'x']])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');

        $first = $this->start('chave-fixa-123')->assertStatus(202);
        $second = $this->start('chave-fixa-123')->assertStatus(202)->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('instance.id'), $second->json('instance.id'));
        $this->assertSame(1, ProcessInstance::count());
        $this->assertSame(1, EngineCommand::where('command', 'START')->count());
        $this->start('chave-fixa-123', ['item' => 'outro'])->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
    }

    public function test_instance_numbers_are_sequential_per_year(): void
    {
        $a = $this->start()->json('instance.number');
        $b = $this->start()->json('instance.number');
        $yy = now(config('bpm.sequences.timezone'))->format('y');
        $this->assertSame("CMP-000001/$yy", $a);
        $this->assertSame("CMP-000002/$yy", $b);
    }

    public function test_start_shows_processing_until_engine_confirms_and_completes_opening_task(): void
    {
        $res = $this->start()->assertStatus(202);
        $this->assertTrue($res->json('instance.processing'));
        $this->assertSame('starting', $res->json('instance.status'));

        $this->engine->nextTasksOnComplete = ['analise'];
        $this->drainOutbox();
        $instance = ProcessInstance::first();
        $this->assertSame('running', $instance->status);
        $opening = TaskProjection::where('stage_key', 'abertura')->first();
        $this->assertSame(TaskProjection::COMPLETED, $opening->status);
        $this->assertSame($opening->id, FormSubmission::first()->task_projection_id);
        $this->assertSame('GEF', TaskProjection::where('stage_key', 'analise')->first()->candidate_group);
    }

    public function test_lost_start_response_is_reconciled_without_duplicating_the_instance(): void
    {
        $this->engine->loseResponseOnce = ['start'];
        $this->start();
        $this->drainOutbox(1);
        $command = EngineCommand::where('command', 'START')->first();
        $this->assertSame(EngineCommand::RECONCILIATION_REQUIRED, $command->state);

        $this->drainOutbox();
        $this->assertSame(EngineCommand::CONFIRMED, $command->fresh()->state);
        $this->assertSame(1, $this->engine->startCalls, 'Reconciliação encontrou a instância pelo businessKey');
        $this->assertTrue($command->fresh()->response['reconciled']);
        $this->assertSame('running', ProcessInstance::first()->status);
    }

    public function test_lost_complete_response_is_reconciled_from_history(): void
    {
        $instance = $this->startAndReachAnalysis();
        $task = TaskProjection::where('stage_key', 'analise')->first();
        $this->engine->loseResponseOnce = ['complete'];

        $this->as('gef')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['ok' => 'sim']])->assertStatus(202)->assertJsonPath('task.status', 'completing');
        $this->drainOutbox();

        $this->assertSame(TaskProjection::COMPLETED, $task->fresh()->status);
        $this->assertSame(2, $this->engine->completeCalls, 'Abertura + análise: sem conclusão duplicada');
        $this->assertSame('Parafuso', FormSubmission::where('task_projection_id', $task->id)->first()->payload['item'], 'Campo copiado do contexto');
        $this->assertSame($instance->id, $task->process_instance_id);
    }

    public function test_users_outside_the_group_cannot_view_or_complete_tasks_by_id(): void
    {
        $this->startAndReachAnalysis();
        $task = TaskProjection::where('stage_key', 'analise')->first();

        $this->as('fiscal')->getJson("/api/v1/tasks/{$task->id}")->assertNotFound();
        $this->as('fiscal')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['ok' => 'sim']])->assertNotFound();
        $this->as('fiscal')->getJson("/api/v1/process-instances/{$task->process_instance_id}")->assertNotFound();
        // o solicitante vê a instância, mas não executa tarefa de outro grupo
        $this->as('cq')->getJson("/api/v1/process-instances/{$task->process_instance_id}")->assertOk();
        $this->as('cq')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['ok' => 'sim']])->assertForbidden();
        $this->as('auditor')->getJson("/api/v1/process-instances/{$task->process_instance_id}")->assertOk();
        $this->assertSame(0, $this->as('fiscal')->getJson('/api/v1/process-instances')->json('meta.total'));
    }

    public function test_concurrent_claim_and_double_completion_are_rejected_with_conflict(): void
    {
        $this->startAndReachAnalysis();
        User::create(['username' => 'gef2', 'name' => 'GEF 2', 'roles' => ['Bpm.TaskWorker'], 'groups' => ['GEF']]);
        $task = TaskProjection::where('stage_key', 'analise')->first();

        $this->as('gef')->postJson("/api/v1/tasks/{$task->id}/claim")->assertOk()->assertJsonPath('assignee.name', 'Gabriel — GEF');
        $this->as('gef2')->postJson("/api/v1/tasks/{$task->id}/claim")->assertStatus(409);
        $this->as('gef2')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['ok' => 'sim']])->assertForbidden();

        $stale = $task->row_version; // versão antes do claim
        $this->as('gef')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['ok' => 'sim'], 'rowVersion' => $stale])->assertStatus(409)->assertJsonPath('code', 'stale_task');
        $this->as('gef')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['ok' => 'sim']])->assertStatus(202);
        $this->as('gef')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['ok' => 'nao']])->assertStatus(409)->assertJsonPath('code', 'task_unavailable');
        $this->assertTrue(AuditEvent::where('action', 'task.claimed')->exists());
    }

    public function test_grids_filter_by_each_column(): void
    {
        $a = $this->startAndReachAnalysis();
        $b = $this->startAndReachAnalysis();
        $a->update(['requester_area' => 'Almoxarifado Central', 'started_at' => now()->subDays(40)]);
        TaskProjection::where('process_instance_id', $a->id)->where('stage_key', 'analise')->update(['due_at' => now()->subDay(), 'engine_created_at' => now()->subDays(3)]);
        $taskB = TaskProjection::where('process_instance_id', $b->id)->where('stage_key', 'analise')->first();
        $taskB->update(['due_at' => null, 'engine_created_at' => now()->subHour()]);
        $this->as('gef')->postJson("/api/v1/tasks/{$taskB->id}/claim")->assertOk();
        $local = now(config('bpm.sequences.timezone'));

        $instances = fn (array $f) => $this->as('auditor')->getJson('/api/v1/process-instances?'.http_build_query($f))->assertOk()->json('data.*.id');
        $this->assertSame([$a->id], $instances(['number' => '000001']));
        $this->assertCount(2, $instances(['number' => 'COMPRA']), 'Nome do fluxo, sem diferenciar maiúsculas');
        $this->assertCount(2, $instances(['requester' => 'carla']));
        $this->assertSame([$a->id], $instances(['area' => 'ALMOX']));
        $this->assertSame([$a->id], $instances(['q' => 'almox']));
        $this->assertSame([$b->id], $instances(['startedFrom' => $local->toDateString()]));
        $this->assertSame([$a->id], $instances(['startedTo' => $local->copy()->subDays(30)->toDateString()]));
        $this->assertCount(2, $instances(['stage' => 'ANÁLISE']));
        $this->assertSame([], $instances(['stage' => 'abertura']), 'Somente etapas atuais');
        $this->assertSame([$b->id], $instances(['assignee' => 'gabriel']));
        $this->assertCount(2, $instances(['assignee' => 'gef']));
        $this->assertSame([$a->id], $instances(['age' => '31-']));
        $this->assertSame([$b->id], $instances(['age' => '0-7']));
        $this->assertSame([$a->id], $instances(['due' => 'overdue']));
        $this->assertSame([$b->id], $instances(['due' => 'none']));

        $tasks = fn (array $f, string $user = 'gef') => $this->as($user)->getJson('/api/v1/tasks?'.http_build_query($f))->assertOk()->json('data.*.instance.id');
        $this->assertCount(2, $tasks(['scope' => 'mine', 'flow' => 'compra', 'stage' => 'análise']));
        $this->assertSame([$b->id], $tasks(['scope' => 'mine', 'number' => '000002']));
        $this->assertSame([$a->id], $tasks(['scope' => 'mine', 'requester' => 'almox']));
        $this->assertSame([$a->id], $tasks(['scope' => 'mine', 'q' => 'almox']));
        $this->assertSame([$b->id], $tasks(['scope' => 'mine', 'assignee' => 'gabriel']));
        $this->assertSame([$b->id], $tasks(['scope' => 'mine', 'status' => 'claimed']));
        $this->assertSame([$a->id], $tasks(['scope' => 'mine', 'age' => '2-6']));
        $this->assertSame([$b->id], $tasks(['scope' => 'mine', 'age' => '0-0']));
        $this->assertSame([$a->id], $tasks(['scope' => 'mine', 'due' => 'overdue']));
        $this->assertSame([$b->id], $tasks(['scope' => 'mine', 'due' => 'none']));
        $this->assertCount(2, $tasks(['scope' => 'completed', 'completedFrom' => $local->toDateString()], 'cq'));
        $this->assertSame([], $tasks(['scope' => 'completed', 'completedTo' => $local->copy()->subDay()->toDateString()], 'cq'));

        $this->assertSame([$a->id, $b->id], $instances(['sort' => 'number', 'dir' => 'asc']));
        $this->assertSame([$b->id, $a->id], $instances(['sort' => 'age', 'dir' => 'asc']), 'Idade crescente = mais nova primeiro');
        $this->assertCount(2, $instances(['sort' => 'due', 'dir' => 'desc']));
        $this->assertSame([$b->id, $a->id], $tasks(['scope' => 'mine', 'sort' => 'number', 'dir' => 'desc']));
        $this->assertSame([$b->id, $a->id], $tasks(['scope' => 'mine', 'sort' => 'status', 'dir' => 'asc']), 'claimed antes de open');
        $this->assertCount(2, $tasks(['scope' => 'mine', 'sort' => 'requester', 'dir' => 'asc']));
        $this->as('gef')->getJson('/api/v1/tasks?sort=senha')->assertStatus(422);
        $this->as('gef')->getJson('/api/v1/tasks?due=late')->assertStatus(422);
        $this->as('auditor')->getJson('/api/v1/process-instances?startedFrom=22/10/2026')->assertStatus(422);
    }

    public function test_attachments_validate_type_and_require_authorization_to_download(): void
    {
        $exe = UploadedFile::fake()->createWithContent('virus.exe', 'MZ'.str_repeat("\0", 100));
        $this->as('cq')->post('/api/v1/attachments', ['file' => $exe], ['Accept' => 'application/json'])->assertStatus(422);

        $pdf = UploadedFile::fake()->createWithContent('laudo.pdf', "%PDF-1.4\n%%EOF\n");
        $up = $this->as('cq')->post('/api/v1/attachments', ['file' => $pdf], ['Accept' => 'application/json'])->assertCreated();
        $id = $up->json('id');
        $this->as('gef')->getJson("/api/v1/attachments/$id/url")->assertNotFound();

        $this->start(null, ['item' => 'Com anexo', 'anexo' => [$up->json('file')]])->assertStatus(202);
        $url = $this->as('cq')->getJson("/api/v1/attachments/$id/url")->assertOk()->json('url');
        $this->get($url)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=laudo.pdf');
        $this->get(preg_replace('/signature=[^&]+/', 'signature=forjada', $url))->assertForbidden();

        $preview = $this->as('cq')->withHeader('X-Forwarded-Proto', 'https')
            ->getJson("/api/v1/attachments/$id/url?disposition=inline")
            ->assertOk()->assertJsonPath('type', 'application/pdf')->assertJsonPath('previewable', true)->json('url');
        $this->assertStringStartsWith('https://', $preview);
        $this->get($preview)->assertOk()->assertHeader('Content-Disposition', 'inline; filename=laudo.pdf');
        $this->get(str_replace('disposition=inline', 'disposition=attachment', $preview))->assertForbidden();
        $this->flushHeaders();
        $this->as('cq')->getJson("/api/v1/attachments/$id/url?disposition=invalid")->assertUnprocessable();

        $text = $this->as('cq')->post('/api/v1/attachments', [
            'file' => UploadedFile::fake()->createWithContent('notas.txt', 'Sem visualizador.'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $textUrl = $this->as('cq')->getJson("/api/v1/attachments/$text/url?disposition=inline")
            ->assertOk()->assertJsonPath('previewable', false)->json('url');
        $this->get($textUrl)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=notas.txt');

        // anexo de outro usuário não pode ser usado em submissão
        $other = $this->as('designer')->post('/api/v1/attachments', ['file' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n%%EOF\n")], ['Accept' => 'application/json'])->assertCreated();
        $this->start(null, ['item' => 'x', 'anexo' => [$other->json('file')]])->assertStatus(422);
    }

    public function test_unauthenticated_api_requests_get_401_even_without_json_accept_header(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_problem_details_and_correlation_id_on_errors(): void
    {
        $res = $this->as('cq')->withHeader('X-Correlation-Id', 'corr-12345678')->getJson('/api/v1/tasks/'.Str::uuid());
        $res->assertNotFound()->assertHeader('X-Correlation-Id', 'corr-12345678')->assertJsonPath('correlationId', 'corr-12345678')->assertJsonPath('status', 404);
    }
}
