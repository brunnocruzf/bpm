<?php

namespace Tests\Feature;

use App\Flow\FlowBlueprint;
use App\Flow\PublishService;
use App\Forms\FormioBuilder as F;
use App\Models\AuditEvent;
use App\Models\FormSchemaVersion;
use App\Models\FormSubmission;
use App\Models\ProcessInstance;
use App\Models\ProcessStageVersion as S;
use App\Models\ReportJob;
use App\Models\ReportTemplate;
use App\Models\TaskProjection;
use App\Models\User;
use App\Reports\CarboneReportRenderer;
use App\Reports\InstanceReportBuilder;
use App\Reports\OperationalReports;
use App\Reports\ReportDocument;
use App\Reports\ReportRenderException;
use App\Reports\ReportService;
use App\Reports\ReportTemplateService;
use App\Reports\TabularWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeFlowable;
use Tests\TestCase;
use ZipArchive;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private FakeFlowable $engine;

    private string $instanceId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments_local');
        $this->seedIdentity();
        $this->engine = FakeFlowable::install();
        $catalog = FlowBlueprint::create([
            'key' => 'devolucao', 'name' => 'Devolução', 'category' => 'Qualidade', 'prefix' => 'DEV',
            'stages' => [
                ['key' => 'inicio', 'name' => 'Início', 'type' => S::START],
                ['key' => 'abertura', 'name' => 'Abertura', 'type' => S::USER_TASK, 'lane' => 'CQ', 'form' => ['components' => [
                    F::text('item', 'Item devolvido', ['required' => true]),
                    F::text('cpfMotorista', 'CPF do motorista'),
                    F::text('observacao', 'Observação interna', ['properties' => ['tmsaSensitive' => 'true']]),
                    F::text('vazio', 'Campo opcional'),
                    F::yesNo('temFoto', 'Tem foto?'),
                    F::text('detalheFoto', 'Detalhe da foto', ['when' => ['temFoto' => 'sim']]),
                ]]],
                ['key' => 'analise', 'name' => 'Análise GEF', 'type' => S::USER_TASK, 'lane' => 'GEF', 'form' => ['components' => [F::copied(F::text('item', 'Item devolvido')), F::textarea('parecer', 'Parecer', ['required' => true])]]],
                ['key' => 'fim', 'name' => 'Fim', 'type' => S::END],
            ],
            'transitions' => [['inicio', 'abertura'], ['abertura', 'analise'], ['analise', 'fim']],
        ], $this->dev('designer'));
        app(PublishService::class)->publish($catalog->latestVersion(), $this->dev('publisher'));
        $this->drainOutbox();
        app(ReportTemplateService::class)->ensureDefaults();
        $this->engine->nextTasksOnStart = ['abertura'];
        $this->engine->nextTasksOnComplete = ['analise'];
        $this->instanceId = $this->as('cq')->withHeader('Idempotency-Key', 'k-'.Str::random(10))->postJson('/api/v1/process-instances', ['flowKey' => 'devolucao', 'data' => [
            'item' => 'Rolamento 6204', 'cpfMotorista' => '123.456.789-00', 'observacao' => 'Fornecedor reincidente', 'temFoto' => 'nao',
        ]])->assertStatus(202)->json('instance.id');
        $this->drainOutbox();
        $this->engine->nextTasksOnComplete = [];
        $task = TaskProjection::where('stage_key', 'analise')->first();
        $this->as('gef')->postJson("/api/v1/tasks/{$task->id}/complete", ['data' => ['parecer' => 'Procedente']])->assertStatus(202);
        $this->drainOutbox();
    }

    private function generate(string $user, array $body): ReportJob
    {
        $id = $this->as($user)->withHeader('Idempotency-Key', 'r-'.Str::random(12))->postJson("/api/v1/process-instances/{$this->instanceId}/reports", $body)->assertStatus(202)->json('id');
        $this->assertSame(ReportJob::QUEUED, ReportJob::find($id)->status, 'geração fora da requisição interativa');
        $this->drainReports();

        return ReportJob::find($id);
    }

    private function doc(string $user, array $params): ReportDocument
    {
        return app(InstanceReportBuilder::class)->build(ProcessInstance::find($this->instanceId), $this->dev($user), $params);
    }

    private function rows(ReportDocument $doc): array
    {
        $rows = [];
        foreach ($doc->blocks as $b) {
            if ($b['type'] === 'kv') {
                foreach ($b['rows'] as [$k, $v]) {
                    $rows[$k] = $v;
                }
            }
        }

        return $rows;
    }

    public function test_single_stage_multiple_stages_and_full_report(): void
    {
        $single = $this->doc('cq', ['mode' => 'stages', 'stages' => ['analise']]);
        $headings = array_column(array_filter($single->blocks, fn ($b) => $b['type'] === 'heading'), 'text');
        $this->assertContains('Etapa: Análise GEF', $headings);
        $this->assertNotContains('Etapa: Abertura', $headings);
        $this->assertSame('Procedente', $this->rows($single)['Parecer']);

        $multi = $this->doc('cq', ['mode' => 'stages', 'stages' => ['abertura', 'analise']]);
        $this->assertCount(2, $multi->data['stages']);
        $full = $this->doc('cq', ['mode' => 'full']);
        $this->assertCount(2, $full->data['stages']);
        $summary = $this->doc('cq', ['mode' => 'summary']);
        $this->assertSame([], $summary->data['stages']);
        $this->assertNotEmpty($summary->data['timeline']);
        $this->assertContains('Formulário submetido', array_column($summary->data['timeline'], 'event'));
    }

    public function test_snapshot_of_submission_and_markers_for_empty_hidden_and_sensitive_fields(): void
    {
        // dado alterado depois (contexto da instância) não muda o histórico da submissão
        $instance = ProcessInstance::find($this->instanceId);
        $instance->update(['data' => [...$instance->data, 'item' => 'Alterado depois']]);
        User::where('username', 'cq')->update(['name' => 'Nome alterado no diretório']);

        $rows = $this->rows($this->doc('gef', ['mode' => 'stages', 'stages' => ['abertura']]));
        $this->assertSame('Rolamento 6204', $rows['Item devolvido']);
        $this->assertSame('não preenchido', $rows['Campo opcional']);
        $this->assertSame('não aplicável', $rows['Detalhe da foto']);
        $this->assertSame('•••• restrito', $rows['CPF do motorista'], 'PII típica mascarada');
        $this->assertSame('•••• restrito', $rows['Observação interna'], 'campo marcado como sensível');
        $doc = $this->doc('gef', ['mode' => 'stages', 'stages' => ['abertura']]);
        $this->assertStringContainsString('Carla — CQ Recebimento', json_encode($doc->data, JSON_UNESCAPED_UNICODE), 'autor = snapshot');

        // autor da submissão e perfil com data.sensitive veem o valor
        $this->assertSame('123.456.789-00', $this->rows($this->doc('cq', ['mode' => 'stages', 'stages' => ['abertura']]))['CPF do motorista']);
        User::create(['username' => 'dpo', 'name' => 'DPO', 'roles' => ['Bpm.Auditor', 'Bpm.SensitiveDataReader'], 'groups' => []]);
        $this->assertSame('Fornecedor reincidente', $this->rows($this->doc('dpo', ['mode' => 'full']))['Observação interna']);
    }

    public function test_historical_schema_is_used_even_after_form_changes(): void
    {
        // simula edição posterior do schema (nova versão) — a submissão aponta o schema usado
        $form = FormSchemaVersion::where('form_key', 'like', '%analise%')->first();
        $sub = FormSubmission::where('stage_version_id', $form->stage_version_id)->first();
        $this->assertSame($form->id, $sub->form_schema_version_id);
        $rows = $this->rows($this->doc('cq', ['mode' => 'stages', 'stages' => ['analise']]));
        $this->assertArrayHasKey('Parecer', $rows);
    }

    public function test_pdf_generation_preview_print_and_audited_download(): void
    {
        $job = $this->generate('cq', ['mode' => 'full']);
        $this->assertSame(ReportJob::READY, $job->status, (string) $job->error_message);
        $this->assertSame('native', $job->renderer);
        $this->assertNotNull($job->expires_at);

        $res = $this->as('cq')->get("/api/v1/reports/{$job->id}/download?purpose=preview");
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $res->streamedContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertMatchesRegularExpression('/\/Count [1-9]/', $pdf);
        $this->assertStringContainsString('inline', (string) $res->headers->get('Content-Disposition'));
        $this->as('cq')->get("/api/v1/reports/{$job->id}/download?purpose=print")->assertOk();
        $this->assertTrue(AuditEvent::where('action', 'report.downloaded')->where('entity_id', $job->id)->exists());
        $this->assertTrue(AuditEvent::where('action', 'report.printed')->where('entity_id', $job->id)->exists());
        $this->assertTrue(AuditEvent::where('action', 'report.generated')->where('entity_id', $job->id)->exists());

        // link não autorizado: outro usuário (mesmo com acesso à instância) não baixa o job alheio
        $this->as('gef')->get("/api/v1/reports/{$job->id}/download")->assertNotFound();
        $this->as('fiscal')->postJson("/api/v1/process-instances/{$this->instanceId}/reports", ['mode' => 'full'])->assertNotFound();
    }

    public function test_idempotent_request_and_validation(): void
    {
        $a = $this->as('cq')->withHeader('Idempotency-Key', 'mesma-chave-1')->postJson("/api/v1/process-instances/{$this->instanceId}/reports", ['mode' => 'summary'])->json('id');
        $b = $this->as('cq')->withHeader('Idempotency-Key', 'mesma-chave-1')->postJson("/api/v1/process-instances/{$this->instanceId}/reports", ['mode' => 'summary'])->json('id');
        $this->assertSame($a, $b);
        $this->as('cq')->postJson("/api/v1/process-instances/{$this->instanceId}/reports", ['mode' => 'stages', 'stages' => ['inexistente']])->assertStatus(422);
    }

    public function test_expired_artifact_is_gone(): void
    {
        $job = $this->generate('cq', ['mode' => 'summary']);
        $job->update(['expires_at' => now()->subMinute()]);
        app(ReportService::class)->expire();
        $this->assertSame(ReportJob::EXPIRED, $job->fresh()->status);
        Storage::disk('attachments_local')->assertMissing((string) $job->artifact_key);
        $this->as('cq')->get("/api/v1/reports/{$job->id}/download")->assertStatus(409)->assertJsonPath('code', 'report_not_ready');
    }

    public function test_operational_reports_in_pdf_csv_xlsx_respect_permission_and_timezone(): void
    {
        $this->as('cq')->postJson('/api/v1/reports', ['type' => 'instances', 'format' => 'csv'])->assertForbidden();
        $this->as('gestor')->postJson('/api/v1/reports', ['type' => 'sql_livre', 'format' => 'csv'])->assertStatus(422);
        $this->as('gestor')->postJson('/api/v1/reports', ['type' => 'instances', 'format' => 'csv', 'filters' => ['openedFrom' => "2026-01-01'; drop"]])->assertStatus(422);

        $today = now(config('bpm.sequences.timezone'))->format('Y-m-d');
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->as('gestor')->postJson('/api/v1/reports', ['type' => 'instances', 'format' => $format, 'filters' => ['openedFrom' => $today, 'openedTo' => $today, 'flowKey' => 'devolucao']])->assertStatus(202);
        }
        foreach (['stage_aging', 'pending', 'sla', 'integration_errors', 'notifications'] as $type) {
            $this->as('gestor')->postJson('/api/v1/reports', ['type' => $type, 'format' => 'pdf'])->assertStatus(202);
        }
        $this->drainReports();
        $this->assertSame(0, ReportJob::where('status', '!=', ReportJob::READY)->count(), json_encode(ReportJob::where('status', '!=', 'ready')->get(['report_type', 'error_message'])));

        $csvJob = ReportJob::where('format', 'csv')->first();
        $this->assertSame(1, $csvJob->row_count);
        $csv = (string) Storage::disk('attachments_local')->get((string) $csvJob->artifact_key);
        $this->assertStringStartsWith("\xEF\xBB\xBF\"Protocolo\";", $csv);
        $this->assertStringContainsString('DEV-000001/', $csv);

        $xlsx = ReportJob::where('format', 'xlsx')->first();
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, (string) Storage::disk('attachments_local')->get((string) $xlsx->artifact_key));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $this->assertStringContainsString('DEV-000001/', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        @unlink($tmp);

        // dia anterior (fuso de negócio) não inclui a instância aberta hoje
        $yesterday = now(config('bpm.sequences.timezone'))->subDay()->format('Y-m-d');
        $doc = app(OperationalReports::class)->build('instances', ['openedFrom' => $yesterday, 'openedTo' => $yesterday], 100);
        $this->assertSame([], $doc->table['rows']);
    }

    public function test_csv_neutralizes_formula_injection(): void
    {
        $csv = TabularWriter::csv([['key' => 'a', 'label' => 'A']], [['a' => '=HYPERLINK("http://x")'], ['a' => -5]]);
        $this->assertStringContainsString("\"'=HYPERLINK", $csv);
        $this->assertStringContainsString('"-5"', $csv);
    }

    public function test_carbone_contract_template_validation_and_timeout(): void
    {
        config(['bpm.reports.carbone.enabled' => true, 'bpm.reports.carbone.base_url' => 'http://carbone.test:4000']);
        // modelo com macro é recusado
        $macro = $this->docx(['word/vbaProject.bin' => 'x']);
        $this->as('admin')->post('/api/v1/admin/report-templates', ['key' => 'inst_carbone', 'kind' => 'instance', 'engine' => 'carbone', 'file' => $macro], ['Accept' => 'application/json'])->assertStatus(422);
        $external = $this->docx(['word/_rels/document.xml.rels' => '<Relationships><Relationship TargetMode="External" Target="http://x"/></Relationships>']);
        $this->as('admin')->post('/api/v1/admin/report-templates', ['key' => 'inst_carbone', 'kind' => 'instance', 'engine' => 'carbone', 'file' => $external], ['Accept' => 'application/json'])->assertStatus(422);
        $this->as('cq')->post('/api/v1/admin/report-templates', ['key' => 'x_y', 'kind' => 'instance'], ['Accept' => 'application/json'])->assertForbidden();

        $id = $this->as('admin')->post('/api/v1/admin/report-templates', ['key' => 'inst_carbone', 'name' => 'Carbone', 'kind' => 'instance', 'engine' => 'carbone', 'file' => $this->docx([])], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $template = ReportTemplate::find($id);

        $captured = null;
        Http::fake(['carbone.test:4000/*' => function (Request $r) use (&$captured) {
            $captured = $r;

            return Http::response('%PDF-1.7 carbone', 200, ['Content-Type' => 'application/pdf']);
        }]);
        $doc = new ReportDocument('Título', 'arquivo');
        $doc->data = ['instance' => ['number' => 'DEV-1']];
        $artifact = app(CarboneReportRenderer::class)->render($doc, 'pdf', $template, ['org' => 'TMSA', 'brandColor' => '#000000', 'confidentiality' => 'Interno', 'footer' => 'f', 'logo' => null]);
        $this->assertSame('%PDF-1.7 carbone', $artifact->bytes);
        $this->assertStringEndsWith('/render/template?download=true', $captured->url());
        $this->assertSame('5', $captured->header('carbone-version')[0]);
        $payload = json_decode($captured->body(), true);
        $this->assertSame('pdf', $payload['convertTo']);
        $this->assertSame('DEV-1', $payload['data']['report']['instance']['number']);
        $this->assertNotEmpty(base64_decode($payload['template'], true));
        $this->assertArrayNotHasKey('database', $payload['data'], 'Carbone recebe somente o DTO');
        $this->assertSame([], $captured->header('Authorization'), 'sem token configurado, nenhum cabeçalho de autenticação');
        config(['bpm.reports.carbone.token' => 'tok-de-desenvolvimento']);
        app(CarboneReportRenderer::class)->render($doc, 'pdf', $template, ['org' => 'TMSA', 'brandColor' => '#000000', 'confidentiality' => 'Interno', 'footer' => 'f', 'logo' => null]);
        $this->assertSame('Bearer tok-de-desenvolvimento', $captured->header('Authorization')[0], 'token de serviço vindo do ambiente');
        config(['bpm.reports.carbone.token' => null]);

        Http::fake(['carbone.test:4000/*' => fn () => throw new ConnectionException('timeout')]);
        try {
            app(CarboneReportRenderer::class)->render($doc, 'pdf', $template, ['org' => 'TMSA', 'brandColor' => '#000000', 'confidentiality' => null, 'footer' => 'f', 'logo' => null]);
            $this->fail('timeout esperado');
        } catch (ReportRenderException $e) {
            $this->assertSame('render_timeout', $e->errorCode);
            $this->assertTrue($e->transient);
        }
        // template alterado fora do fluxo de aprovação é recusado (hash)
        Storage::disk('attachments_local')->put((string) $template->file_key, 'adulterado');
        $this->expectException(ReportRenderException::class);
        app(CarboneReportRenderer::class)->render($doc, 'pdf', $template, ['org' => 'TMSA', 'brandColor' => '#000000', 'confidentiality' => null, 'footer' => 'f', 'logo' => null]);
    }

    public function test_carbone_can_be_selected_without_license_ceremony_and_the_status_reflects_the_platform(): void
    {
        // uso liberado pelo responsável do projeto: sem aprovação de licença; o serviço ainda depende da plataforma
        $this->as('admin')->putJson('/api/v1/admin/settings/reports', ['renderer' => 'carbone'])->assertOk()->assertJsonPath('status', 'error');
        config(['bpm.reports.carbone.enabled' => true]);
        $this->as('admin')->putJson('/api/v1/admin/settings/reports', ['renderer' => 'carbone', 'carbone' => ['edition' => 'enterprise', 'licenseApproved' => false]])
            ->assertOk()->assertJsonPath('status', 'test_pending')->assertJsonPath('value.carbone.edition', 'enterprise')
            ->assertJsonMissingPath('value.carbone.licenseApproved')->assertJsonMissingPath('value.carbone.approvalReference');
        $this->as('cq')->putJson('/api/v1/admin/settings/reports', ['renderer' => 'native'])->assertForbidden();
        $this->as('admin')->putJson('/api/v1/admin/settings/reports', ['renderer' => 'native'])->assertOk()->assertJsonPath('status', 'configured');
        $this->as('admin')->putJson('/api/v1/admin/settings/reports', ['renderer' => 'outro'])->assertStatus(422);
    }

    /** @param array<string,string> $extra */
    private function docx(array $extra): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'd');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<w:document>{d.report.instance.number}</w:document>');
        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return UploadedFile::fake()->createWithContent('modelo.docx', (string) file_get_contents($tmp));
    }
}
