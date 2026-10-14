<?php

namespace Tests\Feature;

use App\Forms\FormioBuilder as F;
use App\Models\AuditEvent;
use App\Models\EngineCommand;
use App\Models\FormSchemaVersion;
use App\Models\ProcessCatalog;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessStageVersion;
use App\Models\ProcessTransitionVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FakeFlowable;
use Tests\TestCase;

class FlowAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
    }

    private function createFlow(string $name = 'Solicitação de compra'): array
    {
        return $this->as('designer')->postJson('/api/v1/flows', ['name' => $name, 'category' => 'Compras', 'description' => 'Teste'])
            ->assertCreated()->json();
    }

    public function test_new_flow_creates_draft_first_human_stage_linked_form_and_initial_path_atomically(): void
    {
        $res = $this->createFlow();

        $this->assertSame('solicitacao_de_compra', $res['flow']['key']);
        $this->assertSame('SC', $res['flow']['instancePrefix']);
        $this->assertSame('draft', $res['version']['status']);
        $stages = collect($res['version']['stages']);
        $this->assertSame(['start', 'user_task', 'end'], $stages->pluck('type')->all());
        $first = $stages->firstWhere('type', 'user_task');
        $this->assertSame('Etapa 1', $first['name']);
        $this->assertSame('Formulário da Etapa 1', $first['form']['title']);
        $this->assertSame('etapa_1', $stages->firstWhere('type', 'start')['outgoing'][0]['target']);
        $this->assertSame('fim', $first['outgoing'][0]['target']);
        $this->assertSame('etapa_1', $res['version']['openingStageKey']);
    }

    public function test_flow_creation_rolls_back_completely_on_failure(): void
    {
        ProcessTransitionVersion::creating(fn () => throw new RuntimeException('falha simulada'));
        $this->withoutExceptionHandling();
        try {
            $this->createFlow('Fluxo que falha');
        } catch (RuntimeException) {
        }
        $this->assertSame(0, ProcessCatalog::count());
        $this->assertSame(0, ProcessStageVersion::count());
        $this->assertSame(0, FormSchemaVersion::count());
    }

    public function test_only_designers_can_create_flows(): void
    {
        $this->as('cq')->postJson('/api/v1/flows', ['name' => 'X fluxo', 'category' => 'Compras'])->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'forbidden');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/flows', ['name' => 'X fluxo', 'category' => 'Compras'])->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer dev:inexistente')->postJson('/api/v1/flows', ['name' => 'X fluxo', 'category' => 'Compras'])->assertUnauthorized();
    }

    public function test_adding_stages_links_forms_only_to_human_tasks(): void
    {
        $this->createFlow();
        $base = '/api/v1/definitions/solicitacao_de_compra/versions/1';

        $v = $this->as('designer')->postJson("$base/stages", ['type' => 'user_task', 'after' => 'etapa_1'])->assertCreated()->json('version');
        $etapa2 = collect($v['stages'])->firstWhere('name', 'Etapa 2');
        $this->assertNotNull($etapa2['form']);
        $this->assertSame($etapa2['key'], collect($v['stages'])->firstWhere('key', 'etapa_1')['outgoing'][0]['target'], 'Inserida na sequência');

        $v = $this->as('designer')->postJson("$base/stages", ['type' => 'exclusive_gateway', 'after' => $etapa2['key']])->assertCreated()->json('version');
        $gateway = collect($v['stages'])->firstWhere('type', 'exclusive_gateway');
        $this->assertNull($gateway['form']);
        $this->assertSame(2, FormSchemaVersion::count(), 'Gateway não cria formulário órfão');

        // não remove a última tarefa humana
        $this->as('designer')->deleteJson("$base/stages/{$etapa2['key']}")->assertOk();
        $this->as('designer')->deleteJson("$base/stages/etapa_1")->assertStatus(409)->assertJsonPath('code', 'last_human_task');
    }

    public function test_draft_flow_can_be_deleted_and_draft_version_discarded_but_published_versions_never(): void
    {
        // fluxo nunca publicado: exclusão com confirmação pelo identificador, em cascata
        $this->createFlow('Fluxo descartável');
        $this->as('cq')->deleteJson('/api/v1/flows/fluxo_descartavel?confirm=fluxo_descartavel')->assertForbidden();
        $this->as('designer')->deleteJson('/api/v1/flows/fluxo_descartavel')->assertStatus(422)->assertJsonValidationErrors(['confirm'], 'errors');
        $catalogId = ProcessCatalog::where('key', 'fluxo_descartavel')->value('id');
        $this->as('designer')->deleteJson('/api/v1/flows/fluxo_descartavel?confirm=fluxo_descartavel')->assertNoContent();
        $this->assertNull(ProcessCatalog::find($catalogId));
        $this->assertSame(0, ProcessDefinitionVersion::where('catalog_id', $catalogId)->count());
        $this->assertSame(0, FormSchemaVersion::whereNotIn('definition_version_id', ProcessDefinitionVersion::pluck('id'))->count(), 'formulários do rascunho removidos');
        $deleted = AuditEvent::where('action', 'flow.deleted')->firstOrFail();
        $this->assertSame('fluxo_descartavel', $deleted->data['key']);
        $this->as('designer')->getJson('/api/v1/flows/fluxo_descartavel')->assertNotFound();

        // publicado: nunca é excluído; o rascunho da v2 pode ser descartado e a v1 continua
        $this->configureValidFlow();
        FakeFlowable::install();
        $this->as('publisher')->postJson('/api/v1/definitions/solicitacao_de_compra/versions/1/publish')->assertStatus(202);
        $this->drainOutbox();
        $this->as('designer')->deleteJson('/api/v1/flows/solicitacao_de_compra?confirm=solicitacao_de_compra')->assertStatus(409)->assertJsonPath('code', 'flow_published');
        $this->as('designer')->deleteJson('/api/v1/definitions/solicitacao_de_compra/versions/1')->assertStatus(409)->assertJsonPath('code', 'not_draft');
        $this->as('designer')->postJson('/api/v1/definitions/solicitacao_de_compra/versions')->assertCreated();
        $this->as('designer')->deleteJson('/api/v1/definitions/solicitacao_de_compra/versions/2')->assertNoContent();
        $this->assertSame([1], ProcessCatalog::where('key', 'solicitacao_de_compra')->firstOrFail()->versions()->pluck('version')->all());
        $this->assertTrue(AuditEvent::where('action', 'version.draft_discarded')->exists());
        $this->as('designer')->getJson('/api/v1/flows/solicitacao_de_compra')->assertOk()->assertJsonPath('flow.hasDraft', false);
    }

    public function test_form_is_sanitized_and_published_versions_are_immutable(): void
    {
        $this->configureValidFlow();
        $base = '/api/v1/definitions/solicitacao_de_compra/versions/1';
        $saved = $this->as('designer')->getJson("$base/forms/f_etapa_1")->assertOk()->json('schema');
        $this->assertCount(2, $saved['components']);

        FakeFlowable::install();
        $this->as('publisher')->postJson("$base/publish")->assertStatus(202)->assertJsonPath('status', 'published');
        $this->assertSame(1, EngineCommand::where('command', 'DEPLOY')->count());
        $this->drainOutbox();
        $this->assertNotNull(ProcessCatalog::first()->publishedVersion->engine_definition_id);

        $this->as('designer')->putJson("$base/forms/f_etapa_1", ['schema' => ['components' => []]])->assertStatus(409)->assertJsonPath('code', 'version_not_draft');
        $this->as('designer')->postJson("$base/stages", ['type' => 'user_task'])->assertStatus(409);

        // nova versão clona etapas e formulários com as mesmas chaves estáveis
        $v2 = $this->as('designer')->postJson('/api/v1/definitions/solicitacao_de_compra/versions')->assertCreated()->json();
        $this->assertSame(2, $v2['version']);
        $this->assertSame('f_etapa_1', collect($v2['stages'])->firstWhere('key', 'etapa_1')['form']['formKey']);
    }

    public function test_validation_reports_actionable_problems_and_blocks_publication(): void
    {
        $this->createFlow();
        $base = '/api/v1/definitions/solicitacao_de_compra/versions/1';
        $this->as('designer')->postJson("$base/stages", ['type' => 'exclusive_gateway', 'after' => 'etapa_1'])->assertCreated();

        $problems = collect($this->as('designer')->postJson("$base/validate")->assertOk()->json('problems'));
        $codes = $problems->pluck('code');
        $this->assertTrue($codes->contains('assignee_missing'));
        $this->assertTrue($codes->contains('form_empty'));
        $this->assertSame('etapa_1', $problems->firstWhere('code', 'form_empty')['stageKey']);

        $this->as('publisher')->postJson("$base/publish")->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->as('designer')->postJson("$base/publish")->assertForbidden();
    }

    public function test_gateway_conditions_must_reference_existing_fields(): void
    {
        $this->configureValidFlow();
        $base = '/api/v1/definitions/solicitacao_de_compra/versions/1';
        $v = $this->as('designer')->postJson("$base/stages", ['type' => 'exclusive_gateway', 'after' => 'etapa_1'])->json('version');
        $gw = collect($v['stages'])->firstWhere('type', 'exclusive_gateway')['key'];
        $this->as('designer')->postJson("$base/stages", ['type' => 'end'])->assertCreated();
        $ends = ProcessStageVersion::where('stage_type', 'end')->pluck('stage_key')->all();

        $this->as('designer')->putJson("$base/stages/$gw/transitions", ['transitions' => [
            ['target' => $ends[0], 'condition' => ['rules' => [['field' => 'campoInexistente', 'operator' => 'eq', 'value' => 'x']]]],
            ['target' => $ends[1], 'isDefault' => true],
        ]])->assertOk();
        $codes = collect($this->as('designer')->postJson("$base/validate")->json('problems'))->pluck('code');
        $this->assertTrue($codes->contains('condition_field'));

        $this->as('designer')->putJson("$base/stages/$gw/transitions", ['transitions' => [
            ['target' => $ends[0], 'condition' => ['rules' => [['field' => 'aprovado', 'operator' => 'eq', 'value' => 'sim']]]],
            ['target' => $ends[1], 'isDefault' => true],
        ]])->assertOk();
        $result = $this->as('designer')->postJson("$base/validate")->json();
        $this->assertTrue($result['valid'], json_encode($result['problems'], JSON_UNESCAPED_UNICODE));

        // injeção na DSL é rejeitada já na gravação
        $this->as('designer')->putJson("$base/stages/$gw/transitions", ['transitions' => [
            ['target' => $ends[0], 'condition' => ['rules' => [['field' => 'a) || true || (b', 'operator' => 'eq', 'value' => 'x']]]],
        ]])->assertStatus(422);
    }

    public function test_bpmn_export_and_import_round_trip_preserves_forms_and_rejects_unsupported_elements(): void
    {
        $this->configureValidFlow();
        $base = '/api/v1/definitions/solicitacao_de_compra/versions/1';
        $xml = $this->as('designer')->get("$base/bpmn")->assertOk()->getContent();
        $this->assertStringContainsString('flowable:candidateGroups="SUP"', $xml);
        $this->assertStringContainsString('<bpmndi:BPMNShape', $xml);

        $formId = ProcessStageVersion::where('stage_key', 'etapa_1')->first()->form_schema_version_id;
        $renamed = str_replace('name="Etapa 1"', 'name="Registrar pedido"', $xml);
        $summary = $this->as('designer')->postJson("$base/bpmn/import", ['xml' => $renamed])->assertOk()->json('summary');
        $this->assertSame([], $summary['removed']);
        $stage = ProcessStageVersion::where('stage_key', 'etapa_1')->first();
        $this->assertSame('Registrar pedido', $stage->name);
        $this->assertSame($formId, $stage->form_schema_version_id, 'Formulário preservado pelo element_id');

        $script = str_replace('<endEvent id="fim"', '<scriptTask id="s1" name="Script" scriptFormat="groovy"><script>System.exit(0)</script></scriptTask><endEvent id="fim"', $xml);
        $this->as('designer')->postJson("$base/bpmn/import", ['xml' => $script])->assertStatus(422);
        $free = str_replace('<sequenceFlow id="flow_etapa_1_fim"', '<sequenceFlow id="flow_etapa_1_fim"><conditionExpression>${true}</conditionExpression></sequenceFlow><sequenceFlow id="x2"', $xml);
        $this->as('designer')->postJson("$base/bpmn/import", ['xml' => $free])->assertStatus(422);
        $this->as('designer')->postJson("$base/bpmn/import", ['xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'])->assertStatus(422);
    }

    private function configureValidFlow(): void
    {
        $this->createFlow();
        $base = '/api/v1/definitions/solicitacao_de_compra/versions/1';
        $this->as('designer')->patchJson("$base/stages/etapa_1", ['lane' => 'SUP'])->assertOk();
        $this->as('designer')->putJson("$base/forms/f_etapa_1", ['schema' => ['components' => [
            F::text('item', 'Item', ['required' => true]) + ['customConditional' => 'alert(1)'],
            F::yesNo('aprovado', 'Aprovado?', ['required' => true]),
        ]]])->assertOk();
    }
}
