<?php

namespace Tests\Feature;

use App\Audit\AuditDiff;
use App\Models\SecretEntry;
use App\Notifications\NotificationTemplate;
use App\Reports\OperationalReports;
use App\Reports\Pdf\Metrics;
use App\Reports\Pdf\PdfWriter;
use App\Settings\SecretStore;
use App\Support\ApiProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Unidades de suporte que dependem do container (por isso em Feature). */
class SupportUnitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_writer_paginates_wraps_and_encodes_portuguese_text(): void
    {
        $pdf = new PdfWriter(['title' => 'Relatório — ação', 'org' => 'TMSA', 'confidentiality' => 'Uso interno', 'footer' => 'Gerado em teste', 'brandColor' => '#086b77']);
        $pdf->heading('Solicitação IRR-000001/26');
        $rows = [];
        for ($i = 0; $i < 120; $i++) {
            $rows[] = ["Linha $i", str_repeat('Avaliação técnica com acentuação ç ã é ', 4)];
        }
        $pdf->table([['label' => 'Campo', 'width' => 0.25], ['label' => 'Valor']], $rows);
        $out = $pdf->output();
        $this->assertStringStartsWith('%PDF-1.4', $out);
        preg_match('/\/Count (\d+)/', $out, $m);
        $this->assertGreaterThan(1, (int) $m[1], 'quebra de página com cabeçalho repetido');
        $this->assertSame("A\xE7\xE3o", Metrics::encode('Ação'));
        foreach (Metrics::wrap(str_repeat('palavra ', 80), 9, 200) as $line) {
            $this->assertLessThanOrEqual(200.01, Metrics::width($line, 9));
        }
        $this->assertCount(3, Metrics::wrap(str_repeat('x', 150), 9, 300), 'palavra longa é quebrada por caractere');
    }

    public function test_notification_template_is_closed_and_escaped(): void
    {
        $t = NotificationTemplate::validate(null, null);
        $text = NotificationTemplate::render($t['body'], ['responsavel' => '<b>Ana</b>', 'link' => 'http://localhost/tasks/1']);
        $this->assertStringContainsString('Ana', $text);
        $this->assertStringNotContainsString('<b>', $text);
        $html = NotificationTemplate::html("Olá <script>x</script>\nhttp://localhost/tasks/1", 'BPM', 'http://localhost');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<a href="http://localhost/tasks/1">', $html);
        $this->assertSame('Linha única', NotificationTemplate::render('{{etapa}}', ['etapa' => "Linha\núnica"], true));
        $this->expectException(ApiProblem::class);
        NotificationTemplate::validate('{{ instance.data.cpf }}', '{{link}}');
    }

    public function test_secret_store_encrypts_rotates_and_reads_mounted_files_only_inside_dir(): void
    {
        $store = app(SecretStore::class);
        $ref = $store->put('teste', 'valor-secreto');
        $this->assertStringStartsWith('db:', $ref);
        $this->assertSame('valor-secreto', $store->get($ref));
        $this->assertStringNotContainsString('valor-secreto', (string) SecretEntry::first()->ciphertext);
        $this->assertArrayNotHasKey('ciphertext', SecretEntry::first()->toArray());

        // rotação: chave antiga vira "previous", nova chave recriptografa
        $old = config('bpm.secrets.key');
        config(['bpm.secrets.key' => 'base64:'.base64_encode(random_bytes(32)), 'bpm.secrets.previous_keys' => [$old]]);
        $this->assertSame('valor-secreto', $store->get($ref));
        $this->assertSame(1, $store->rotate());
        config(['bpm.secrets.previous_keys' => []]);
        $this->assertSame('valor-secreto', $store->get($ref));

        $dir = sys_get_temp_dir().'/bpm-secrets-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/smtp_password", "senha-montada\n");
        config(['bpm.secrets.files_dir' => $dir]);
        $this->assertSame('senha-montada', $store->get('file:smtp_password'));
        $this->assertNull($store->get('file:../etc/passwd'));
        $this->assertFalse(SecretStore::isValidFileRef('file:../x'));
        @unlink("$dir/smtp_password");
        @rmdir($dir);
    }

    public function test_diff_redacts_sensitive_keys_and_percentiles_are_stable(): void
    {
        $diff = AuditDiff::between(['smtp' => ['host' => 'a', 'passwordRef' => 'db:1']], ['smtp' => ['host' => 'b', 'passwordRef' => 'db:2']]);
        $this->assertSame(['from' => 'a', 'to' => 'b'], $diff['smtp.host']);
        $this->assertSame('*** (alterado)', $diff['smtp.passwordRef']['to']);
        $this->assertSame(2.5, OperationalReports::percentile([1, 2, 3, 4], 0.5));
        $this->assertSame(3.7, OperationalReports::percentile([1, 2, 3, 4], 0.9));
        $this->assertNull(OperationalReports::percentile([], 0.5));
        [$from, $to] = app(OperationalReports::class)->dayRange('2026-10-13', '2026-10-13');
        $this->assertSame('2026-10-13 03:00:00', $from->format('Y-m-d H:i:s'), 'início do dia em America/Sao_Paulo (UTC-3)');
        $this->assertSame('2026-10-14 02:59:59', $to->format('Y-m-d H:i:s'));
    }
}
