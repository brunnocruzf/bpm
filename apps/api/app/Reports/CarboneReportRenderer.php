<?php

namespace App\Reports;

use App\Models\ReportTemplate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Carbone self-hosted (rede interna, porta não publicada). Laravel envia o modelo aprovado
 * (base64, sem armazenar no Carbone) e o DTO já autorizado — o Carbone não acessa banco,
 * AX, Graph, Flowable nem o storage. API: POST /render/template?download=true (v5).
 * Usado quando o renderer 'carbone' está selecionado e BPM_CARBONE_ENABLED=true (ADR-0011).
 */
class CarboneReportRenderer implements ReportRenderer
{
    public function name(): string
    {
        return 'carbone';
    }

    public function supports(string $format): bool
    {
        return in_array($format, ['pdf', 'xlsx'], true);
    }

    public function render(ReportDocument $doc, string $format, ?ReportTemplate $template, array $branding): RenderedArtifact
    {
        $cfg = (array) config('bpm.reports.carbone');
        if (! $cfg['enabled']) {
            throw new ReportRenderException('renderer_unavailable', 'Carbone não habilitado pela plataforma.');
        }
        if (! $template || $template->engine !== 'carbone' || ! $template->file_key) {
            throw new ReportRenderException('template_invalid', 'Modelo Carbone não configurado para este relatório.');
        }
        $file = Storage::disk((string) $template->file_disk)->get((string) $template->file_key);
        if ($file === null || hash('sha256', $file) !== $template->file_sha256) {
            throw new ReportRenderException('template_invalid', 'Arquivo do modelo ausente ou alterado fora do fluxo de aprovação.');
        }
        $payload = [
            'data' => ['report' => $doc->data, 'branding' => ['org' => $branding['org'], 'confidentiality' => $branding['confidentiality'], 'footer' => $branding['footer']]],
            'convertTo' => $format,
            'template' => base64_encode($file),
            'reportName' => preg_replace('/[^A-Za-z0-9_\-]/', '_', $doc->fileName),
            'timezone' => (string) config('bpm.sequences.timezone'),
            'lang' => 'pt-br',
        ];
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (strlen($body) > 20 * 1024 * 1024) {
            throw new ReportRenderException('too_large', 'Dados do relatório acima do limite para o renderer.');
        }
        $token = $cfg['token_file'] && is_readable((string) $cfg['token_file']) ? trim((string) file_get_contents((string) $cfg['token_file'])) : null;
        $token = $token ?: ((string) ($cfg['token'] ?? '') ?: null);
        try {
            $request = Http::timeout((int) $cfg['timeout'])->connectTimeout(5)->withHeaders(['carbone-version' => '5'])->withBody($body, 'application/json');
            if ($token) {
                $request = $request->withToken($token);
            }
            $response = $request->post($cfg['base_url'].'/render/template?download=true');
        } catch (ConnectionException) {
            throw new ReportRenderException('render_timeout', 'Carbone não respondeu no tempo limite.', true);
        }
        $contentType = (string) $response->header('Content-Type');
        if (! $response->successful() || str_contains($contentType, 'application/json')) {
            $status = $response->status();
            throw match (true) {
                $status === 401 => new ReportRenderException('renderer_unavailable', 'Carbone recusou a autenticação service-to-service.'),
                $status === 400 || $status === 415 || $status === 404 => new ReportRenderException('template_invalid', 'Carbone recusou o modelo ou os dados.'),
                $status === 413 => new ReportRenderException('too_large', 'Carbone recusou: tamanho acima do limite.'),
                $status >= 500 || $status === 0 => new ReportRenderException('render_failed', 'Carbone falhou ao gerar o documento.', true),
                default => new ReportRenderException('render_failed', 'Resposta inesperada do Carbone (HTTP '.$status.').'),
            };
        }

        return RenderedArtifact::forFormat($response->body(), $format);
    }

    /** @return array{ok:bool, code:string, message:string} */
    public static function health(): array
    {
        $cfg = (array) config('bpm.reports.carbone');
        if (! $cfg['enabled']) {
            return ['ok' => false, 'code' => 'disabled', 'message' => 'Carbone desabilitado (BPM_CARBONE_ENABLED=false).'];
        }
        try {
            $r = Http::timeout(5)->connectTimeout(3)->get($cfg['base_url'].'/status');

            return $r->successful() ? ['ok' => true, 'code' => 'ok', 'message' => 'Carbone respondeu ao /status.'] : ['ok' => false, 'code' => 'http_'.$r->status(), 'message' => 'Carbone respondeu HTTP '.$r->status().'.'];
        } catch (ConnectionException) {
            return ['ok' => false, 'code' => 'unreachable', 'message' => 'Carbone inacessível na rede interna.'];
        }
    }
}
