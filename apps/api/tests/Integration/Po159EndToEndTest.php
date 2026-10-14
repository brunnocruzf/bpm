<?php

namespace Tests\Integration;

use App\Engine\ExternalJobWorker;
use App\Engine\ProjectionSync;
use App\Flow\FlowBlueprint;
use App\Flow\PublishService;
use App\Models\FormSubmission;
use App\Models\ProcessInstance;
use App\Models\SupplierAction;
use App\Models\TaskProjection;
use Database\Seeders\Blueprints\Po159;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Cenário ponta a ponta do PO 159 contra um Flowable REST REAL.
 * Executar com: FLOWABLE_IT_URL=http://127.0.0.1:8090/flowable-rest FLOWABLE_IT_USER=... FLOWABLE_IT_PASSWORD=... php artisan test --testsuite=Integration
 */
#[Group('flowable')]
class Po159EndToEndTest extends TestCase
{
    use RefreshDatabase;

    private string $instanceId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments_local');
        if (! getenv('FLOWABLE_IT_URL')) {
            $this->markTestSkipped('Defina FLOWABLE_IT_URL para rodar contra um Flowable REST real.');
        }
        config([
            'bpm.engine.base_url' => rtrim((string) getenv('FLOWABLE_IT_URL'), '/'),
            'bpm.engine.username' => getenv('FLOWABLE_IT_USER'),
            'bpm.engine.password' => getenv('FLOWABLE_IT_PASSWORD'),
        ]);
        $this->seedIdentity();
        // chave única por execução para não colidir com deploys anteriores no mesmo engine
        $spec = Po159::spec();
        $spec['key'] = 'irr_it_'.Str::lower(Str::random(6));
        $catalog = FlowBlueprint::create($spec, $this->dev('designer'));
        app(PublishService::class)->publish($catalog->latestVersion(), $this->dev('publisher'));
        $this->drainOutbox();
        $this->assertNotNull($catalog->fresh()->publishedVersion->engine_definition_id, 'Deploy não confirmado');
        $this->flowKey = $catalog->key;
    }

    private string $flowKey;

    public function test_po159_full_route_with_contest_parallel_tracks_action_plan_integration_and_timer(): void
    {
        // 01 — abertura (CQ), com consultas AX simuladas
        $start = $this->as('cq')->withHeader('Idempotency-Key', 'it-start-'.Str::random(10))->postJson('/api/v1/process-instances', [
            'flowKey' => $this->flowKey,
            'data' => ['nCan' => 'CAN-123', 'nf' => 4521, 'tpr' => 'TPR-778', 'codigoItem' => 'ITEM-1001', 'ordemCompra' => 'OC-55', 'cliente' => 'forjado pelo cliente'],
        ]);
        $start->assertStatus(202);
        $this->instanceId = $start->json('instance.id');
        $this->assertMatchesRegularExpression('#^IRR-\d{6}/\d{2}$#', $start->json('instance.number'));
        $this->drainOutbox();

        $instance = ProcessInstance::find($this->instanceId);
        $this->assertSame(ProcessInstance::RUNNING, $instance->status);
        $this->assertNotSame('forjado pelo cliente', $instance->data['cliente'], 'Campo somente leitura deve vir do AX, não do cliente');
        $this->assertNotEmpty($instance->data['fornecedorCodigo']);

        // 05–08 urgente → 09 Comitê → 11
        $this->complete('ppcpm', 'a05_analise_previa', ['tipoItem' => '03', 'urgente' => 'sim', 'dataPrazo' => '2026-10-30T00:00:00-03:00', 'justificativaUrgencia' => 'Linha parada']);
        $this->complete('comite', 'a09_comite_urgencia', ['avaliacaoUrgencia' => 'Urgência confirmada']);

        // 11 — liberação condicional exige responsável
        $task = $this->task('a11_gerar_racf');
        $detail = $this->as('gef')->getJson("/api/v1/tasks/{$task->id}")->assertOk();
        $this->assertSame('CAN-123', $detail->json('data.nCan'), 'Campos da 01 devem vir copiados');
        $racf = ['fornecedorCodigo' => $detail->json('data.fornecedorCodigo'), 'prazoResposta' => '2026-11-10T00:00:00-03:00', 'decisaoImediata' => 'liberado_condicional', 'reincidente' => 'nao', 'descricaoProblema' => 'Dureza fora da especificação'];
        $this->as('gef')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => $racf])->assertStatus(422)->assertJsonPath('errors.responsavelLiberacao.0', fn ($m) => str_contains($m, 'Preencha'));
        $this->complete('gef', 'a11_gerar_racf', $racf + ['responsavelLiberacao' => 'Clara — Comitê']);
        $racfNumber = ProcessInstance::find($this->instanceId)->data['numeroRacf'];
        $this->assertMatchesRegularExpression('#^RACF-\d{6}/\d{2}$#', $racfNumber);

        // 24 não procede → 26 GEF discorda e pede Comitê → 10 → 11 (revisão) → 24 procede
        $this->complete('fornecedor', 'a24_analise_fornecedor', ['procede' => 'nao', 'justificativaNaoProcede' => 'Lote dentro da tolerância']);
        $this->complete('gef', 'a26_gef_np', ['gefConcordaNp' => 'nao', 'necessitaComite' => 'sim', 'parecerGef' => 'Ensaio confirma NC']);
        $this->complete('comite', 'a10_comite_np', ['avaliacaoNp' => 'Não procedência rejeitada']);
        $this->complete('gef', 'a11_gerar_racf', $racf + ['responsavelLiberacao' => 'Clara — Comitê', 'observacoesRacf' => 'Revisão após Comitê']);
        $this->assertSame($racfNumber, ProcessInstance::find($this->instanceId)->data['numeroRacf'], 'Nº RACF deve ser estável entre revisões');
        $this->assertSame(2, FormSubmission::where('process_instance_id', $this->instanceId)->whereHas('stage', fn ($q) => $q->where('stage_key', 'a11_gerar_racf'))->count(), 'Histórico de revisões preservado');
        $this->complete('fornecedor', 'a24_analise_fornecedor', ['procede' => 'sim']);

        // Trilhas paralelas
        $this->assertNotNull($this->task('a30_analise_racf'));
        $this->assertNotNull($this->task('a33_garantia_devolucao'));

        // Trilha A: plano reprovado, revisado e executado ação a ação
        $this->complete('fornecedor', 'a30_analise_racf', ['causaRaiz' => 'Tratamento térmico', 'acoes' => [
            ['acao' => 'Recalibrar forno', 'responsavel' => 'Eng. Processo', 'prazo' => '2026-11-01T00:00:00-03:00', 'local' => 'Planta 1'],
            ['acao' => 'Reinspecionar lote', 'responsavel' => 'CQ fornecedor', 'prazo' => '2026-11-03T00:00:00-03:00'],
        ]]);
        $this->complete('gef', 'a31_valida_racf', ['validaAnalise' => 'nao', 'motivoReprovacao' => 'Causa raiz incompleta']);
        $this->complete('gef', 'a32_revisa_racf', ['prazoResposta' => '2026-11-20T00:00:00-03:00', 'decisaoImediata' => 'devolucao', 'descricaoProblema' => 'Dureza fora da especificação (revisado)']);
        $this->complete('fornecedor', 'a30_analise_racf', ['causaRaiz' => 'Tratamento térmico + controle de entrada', 'acoes' => [
            ['acao' => 'Recalibrar forno e validar curva', 'responsavel' => 'Eng. Processo', 'prazo' => '2026-11-05T00:00:00-03:00'],
        ]]);
        $this->assertSame(2, SupplierAction::where('process_instance_id', $this->instanceId)->where('status', SupplierAction::SUPERSEDED)->count());
        $this->complete('gef', 'a31_valida_racf', ['validaAnalise' => 'sim']);

        $exec = $this->task('a301_executa_plano');
        $payload = ['necessitaPreventiva' => 'nao', 'justificativaSemPreventiva' => 'Ação corretiva elimina a causa'];
        $this->as('fornecedor')->postJson("/api/v1/tasks/{$exec->id}/complete", ['data' => $payload])->assertStatus(422);
        $evidence = $this->as('fornecedor')->post('/api/v1/attachments', ['taskId' => $exec->id, 'file' => $this->pdf('evidencia.pdf')], ['Accept' => 'application/json'])->assertCreated();
        foreach (SupplierAction::where('process_instance_id', $this->instanceId)->where('status', SupplierAction::PENDING)->get() as $action) {
            $this->as('fornecedor')->postJson("/api/v1/tasks/{$exec->id}/actions/{$action->id}/complete", ['notes' => 'Executado', 'attachmentIds' => [$evidence->json('id')]])->assertOk();
        }
        $this->complete('fornecedor', 'a301_executa_plano', $payload);
        $this->complete('gef', 'a302_eficacia', ['planoEficaz' => 'sim']);

        // Trilha B: devolução sem NF espelho → integração AX → separação → FOB (timer) → faturamento
        $this->complete('fornecedor', 'a33_garantia_devolucao', ['tratativa' => 'devolucao']);
        $pdf = $this->as('sup')->post('/api/v1/attachments', ['taskId' => $this->task('a45_sup_ocdev')->id, 'fieldKey' => 'pdfOcdev', 'file' => $this->pdf('ocdev.pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->complete('sup', 'a45_sup_ocdev', ['numeroOcdev' => 'OCD-9', 'pdfOcdev' => [$pdf->json('file')], 'necessitaNfEspelho' => 'nao']);

        // service task AX: o worker executa e o Flowable continua o processo de forma assíncrona
        $this->assertGreaterThanOrEqual(1, app(ExternalJobWorker::class)->run());
        $this->waitForTask('a40_separacao', 30);
        $sep = $this->task('a40_separacao');
        $sepDetail = $this->as('qualidade')->getJson("/api/v1/tasks/{$sep->id}")->assertOk();
        $this->assertStringStartsWith('DT-', (string) $sepDetail->json('data.numeroDiarioTransferencia'), 'Resultado da integração AX deve chegar ao formulário');
        $this->complete('qualidade', 'a40_separacao', ['confirmacaoSeparacao' => true]);
        $this->complete('expedicao', 'a41_frete', ['tipoFrete' => 'fob', 'dataColetaFob' => now()->subMinute()->toIso8601String(), 'solicitaEmissaoNf' => true]);

        $this->waitForTask('a44_faturamento', 90);
        $this->complete('faturamento', 'a44_faturamento', ['numeroNfFaturamento' => 'NF-7788']);

        $instance = ProcessInstance::find($this->instanceId);
        $this->assertSame(ProcessInstance::COMPLETED, $instance->status, 'Instância deve encerrar após sincronizar as trilhas');
        $this->assertSame('concluido', $instance->result);
        $this->assertSame('end_ok', $instance->end_stage_key);
        $this->assertSame(0, TaskProjection::where('process_instance_id', $this->instanceId)->whereIn('status', TaskProjection::ACTIVE)->count());
    }

    private function task(string $stageKey): TaskProjection
    {
        $task = TaskProjection::where('process_instance_id', $this->instanceId)->where('stage_key', $stageKey)
            ->whereIn('status', [TaskProjection::OPEN, TaskProjection::CLAIMED])->latest('created_at')->first();
        $this->assertNotNull($task, "Tarefa $stageKey não está aberta. Abertas: ".TaskProjection::where('process_instance_id', $this->instanceId)->whereIn('status', TaskProjection::ACTIVE)->pluck('stage_key')->join(', '));

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

    private function waitForTask(string $stageKey, int $seconds): void
    {
        $sync = app(ProjectionSync::class);
        $deadline = time() + $seconds;
        while (time() < $deadline) {
            $sync->syncInstance(ProcessInstance::find($this->instanceId));
            if (TaskProjection::where('process_instance_id', $this->instanceId)->where('stage_key', $stageKey)->whereIn('status', [TaskProjection::OPEN])->exists()) {
                return;
            }
            sleep(3);
        }
        $this->fail("Timeout aguardando $stageKey (timer do engine).");
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }
}
