<?php

namespace Tests\Unit;

use App\Forms\FormioBuilder as F;
use App\Forms\FormioSchemaSanitizer;
use App\Support\ApiProblem;
use Tests\TestCase;

class FormioSchemaSanitizerTest extends TestCase
{
    private function sanitize(array $components): array
    {
        return app(FormioSchemaSanitizer::class)->sanitize(['components' => $components]);
    }

    public function test_strips_custom_javascript_and_dangerous_properties(): void
    {
        $field = F::text('nome', 'Nome') + [
            'customConditional' => 'show = true; fetch("https://evil")',
            'calculateValue' => 'value = document.cookie',
            'customDefaultValue' => 'value = 1',
            'logic' => [['name' => 'x', 'trigger' => ['type' => 'javascript', 'javascript' => 'alert(1)']]],
        ];
        $field['validate']['custom'] = 'valid = true';
        $field['validate']['json'] = ['if' => []];
        $field['label'] = '<img src=x onerror=alert(1)>Nome';

        $clean = $this->sanitize([$field])['components'][0];

        foreach (['customConditional', 'calculateValue', 'customDefaultValue', 'logic'] as $key) {
            $this->assertArrayNotHasKey($key, $clean);
        }
        $this->assertArrayNotHasKey('custom', $clean['validate']);
        $this->assertArrayNotHasKey('json', $clean['validate']);
        $this->assertSame('Nome', $clean['label']);
    }

    public function test_sanitizes_html_content_and_forces_tmsa_storage(): void
    {
        $out = $this->sanitize([
            F::content('<p onclick="x()">Olá <script>alert(1)</script><a href="javascript:alert(1)">link</a></p>'),
            array_replace(F::file('anexo', 'Anexo'), ['url' => 'https://evil.example/upload', 'storage' => 'url']),
        ])['components'];

        $this->assertStringNotContainsString('script', $out[0]['html']);
        $this->assertStringNotContainsString('onclick', $out[0]['html']);
        $this->assertStringNotContainsString('javascript:', $out[0]['html']);
        $this->assertSame('tmsa', $out[1]['storage']);
        $this->assertArrayNotHasKey('url', $out[1]);
    }

    public function test_time_field_is_a_native_html5_time_input_in_24h(): void
    {
        $out = $this->sanitize([
            F::time('entrada', 'Entrada'),
            ['type' => 'time', 'key' => 'saida', 'label' => 'Saída', 'input' => true, 'inputType' => 'text', 'format' => 'hh:mm A', 'dataFormat' => 'x', 'inputMask' => '99:99'],
        ])['components'];

        foreach ($out as $c) {
            $this->assertSame('time', $c['inputType']);
            $this->assertSame('HH:mm', $c['format']);
            $this->assertSame('HH:mm', $c['dataFormat']);
            $this->assertArrayNotHasKey('inputMask', $c);
        }
    }

    public function test_rejects_unsupported_components_external_data_sources_and_bad_keys(): void
    {
        try {
            $this->sanitize([
                ['type' => 'signature', 'key' => 'assinatura', 'label' => 'Assinatura', 'input' => true],
                array_replace(F::select('cidade', 'Cidade', ['a' => 'A']), ['dataSrc' => 'url', 'data' => ['url' => 'https://api.example/cidades']]),
                F::text('1invalida', 'Chave inválida'),
                F::text('dup', 'A'),
                F::text('dup', 'B'),
                F::text('initiator', 'Reservada'),
            ]);
            $this->fail('Deveria rejeitar');
        } catch (ApiProblem $e) {
            $errors = json_encode($e->errors, JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('não é suportado', $errors);
            $this->assertStringContainsString('valores fixos', $errors);
            $this->assertStringContainsString('inválida', $errors);
            $this->assertStringContainsString('repetida', $errors);
            $this->assertStringContainsString('reservada', $errors);
        }
    }

    public function test_drops_buttons_and_keeps_supported_conditionals(): void
    {
        $out = $this->sanitize([
            ['type' => 'button', 'key' => 'submit', 'label' => 'Enviar', 'action' => 'submit'],
            F::yesNo('urgente', 'Urgente?'),
            F::date('prazo', 'Prazo', ['when' => ['urgente' => 'sim']]),
        ])['components'];

        $this->assertCount(2, $out);
        $this->assertSame(['show' => true, 'conjunction' => 'all', 'conditions' => [['component' => 'urgente', 'operator' => 'isEqual', 'value' => 'sim']]], $out[1]['conditional']);
    }

    public function test_validates_tmsa_auto_and_lookup_extensions(): void
    {
        $this->expectException(ApiProblem::class);
        $this->sanitize([
            F::text('x', 'X') + ['properties' => ['tmsaAuto' => 'php:system']],
            F::lookup('tpr', 'TPR', 'tpr', ['senha' => 'cliente']),
        ]);
    }
}
