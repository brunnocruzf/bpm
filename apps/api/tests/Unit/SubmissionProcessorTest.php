<?php

namespace Tests\Unit;

use App\Forms\FormioBuilder as F;
use App\Forms\ProcessedSubmission;
use App\Forms\SubmissionContext;
use App\Forms\SubmissionProcessor;
use App\Models\User;
use App\Support\ApiProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionProcessorTest extends TestCase
{
    use RefreshDatabase;

    private function ctx(array $context = [], ?User $user = null): SubmissionContext
    {
        $user ??= User::factory()->create(['name' => 'Gabriel', 'area' => 'GEF']);

        return new SubmissionContext(user: $user, instance: null, contextData: $context, instanceInfo: ['number' => 'IRR-000001/26']);
    }

    private function process(array $components, array $input, array $context = []): ProcessedSubmission
    {
        return app(SubmissionProcessor::class)->process(F::form($components), $input, $this->ctx($context));
    }

    public function test_conditional_required_fields_are_enforced_only_when_visible(): void
    {
        $form = [
            F::yesNo('urgente', 'Urgente?', ['required' => true]),
            F::date('prazo', 'Prazo', ['required' => true, 'when' => ['urgente' => 'sim']]),
        ];

        $ok = $this->process($form, ['urgente' => 'nao', 'prazo' => '2026-01-01T00:00:00Z']);
        $this->assertSame(['urgente' => 'nao'], $ok->data, 'Campo oculto é descartado');
        $this->assertSame(['prazo'], $ok->hiddenKeys);
        $this->assertSame(['urgente' => 'nao', 'prazo' => null], $ok->engineVariables(), 'Oculto vai como null para o engine');

        try {
            $this->process($form, ['urgente' => 'sim']);
            $this->fail('Prazo deveria ser obrigatório');
        } catch (ApiProblem $e) {
            $this->assertArrayHasKey('prazo', $e->errors);
        }
    }

    public function test_readonly_fields_come_from_context_not_from_client(): void
    {
        $out = $this->process([F::copied(F::text('nCan', 'Nº CAN')), F::text('obs', 'Obs')], ['nCan' => 'FORJADO', 'obs' => 'ok', 'extra' => 'descartado'], ['nCan' => 'CAN-1']);

        $this->assertSame(['nCan' => 'CAN-1', 'obs' => 'ok'], $out->data);
    }

    public function test_auto_fields_are_server_computed(): void
    {
        $out = $this->process([F::auto('emissor', 'Emissor', 'user.name'), F::auto('area', 'Área', 'user.area'), F::auto('numero', 'Nº', 'instance.number')], ['emissor' => 'Outra pessoa']);

        $this->assertSame('Gabriel', $out->data['emissor']);
        $this->assertSame('GEF', $out->data['area']);
        $this->assertSame('IRR-000001/26', $out->data['numero']);
    }

    public function test_sequence_is_allocated_once_and_kept_on_revision(): void
    {
        $form = [F::auto('numeroRacf', 'Nº RACF', 'sequence:RACF')];
        $first = $this->process($form, []);
        $this->assertMatchesRegularExpression('#^RACF-000001/\d{2}$#', $first->data['numeroRacf']);
        $again = $this->process($form, [], ['numeroRacf' => $first->data['numeroRacf']]);
        $this->assertSame($first->data['numeroRacf'], $again->data['numeroRacf']);
        $this->assertMatchesRegularExpression('#^RACF-000002/\d{2}$#', $this->process($form, [])->data['numeroRacf']);
    }

    public function test_lookup_fills_targets_and_records_source(): void
    {
        $out = $this->process([F::lookup('tpr', 'TPR', 'tpr', ['customerName' => 'cliente'], ['required' => true]), F::text('cliente', 'Cliente', ['disabled' => true])], ['tpr' => 'TPR-1', 'cliente' => 'forjado']);

        $this->assertStringContainsString('fictício', $out->data['cliente']);
        $this->assertSame('MOCK', $out->metadata['lookups']['tpr']['transport']);
    }

    public function test_lookup_not_found_and_unavailable_are_reported_without_losing_data(): void
    {
        $form = [F::lookup('codigoItem', 'Item', 'item', ['itemDescription' => 'descricao'], ['required' => true]), F::text('descricao', 'Descrição', ['disabled' => true])];
        try {
            $this->process($form, ['codigoItem' => 'NAOEXISTE-1']);
            $this->fail();
        } catch (ApiProblem $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('não foi encontrado', $e->errors['codigoItem'][0]);
        }
        try {
            $this->process($form, ['codigoItem' => 'INDISPONIVEL-1']);
            $this->fail();
        } catch (ApiProblem $e) {
            $this->assertSame(503, $e->status);
        }
    }

    public function test_validates_types_options_grids_and_attachments(): void
    {
        $form = [
            F::select('tipo', 'Tipo', ['01' => 'A', '03' => 'B'], ['required' => true]),
            F::number('qtd', 'Qtd', ['required' => true]),
            F::datagrid('acoes', 'Ações', [F::text('acao', 'Ação', ['required' => true])], ['required' => true]),
            F::file('anexo', 'Anexo'),
        ];
        try {
            $this->process($form, ['tipo' => '99', 'qtd' => 'abc', 'acoes' => [['acao' => '']], 'anexo' => [['data' => ['attachmentId' => 'f5b0c7d4-0000-4000-8000-000000000000']]]]);
            $this->fail();
        } catch (ApiProblem $e) {
            $this->assertArrayHasKey('tipo', $e->errors);
            $this->assertArrayHasKey('qtd', $e->errors);
            $this->assertArrayHasKey('acoes[0].acao', $e->errors);
            $this->assertArrayHasKey('anexo', $e->errors, 'Anexo sem permissão deve ser rejeitado');
        }
    }
}
