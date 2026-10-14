<?php

namespace App\Reports;

use App\Models\ReportTemplate;
use App\Reports\Pdf\PdfWriter;
use Throwable;

/** Renderer nativo: PDF (PdfWriter) e tabelas (CSV/XLSX) — sem serviços externos. */
class NativeReportRenderer implements ReportRenderer
{
    public function name(): string
    {
        return 'native';
    }

    public function supports(string $format): bool
    {
        return in_array($format, ['pdf', 'csv', 'xlsx'], true);
    }

    public function render(ReportDocument $doc, string $format, ?ReportTemplate $template, array $branding): RenderedArtifact
    {
        if (! $this->supports($format)) {
            throw new ReportRenderException('unsupported_format', 'Formato não suportado.');
        }
        if ($format !== 'pdf') {
            if (! $doc->table) {
                throw new ReportRenderException('unsupported_format', 'Este relatório não possui formato tabular; use PDF.');
            }

            return RenderedArtifact::forFormat($format === 'csv'
                ? TabularWriter::csv($doc->table['columns'], $doc->table['rows'])
                : TabularWriter::xlsx($doc->table['columns'], $doc->table['rows'], $doc->title), $format);
        }

        try {
            $title = (string) ($template?->option('title') ?: $doc->title);
            $pdf = new PdfWriter([
                'title' => $title,
                'org' => $branding['org'],
                'confidentiality' => $template?->option('confidential', true) === false ? null : $branding['confidentiality'],
                'footer' => $branding['footer'],
                'brandColor' => $branding['brandColor'],
            ], $branding['logo']);
            if ($intro = $template?->option('headerText')) {
                $pdf->paragraph((string) $intro, 'note');
            }
            foreach ($doc->blocks as $b) {
                match ($b['type']) {
                    'heading' => $pdf->heading((string) $b['text'], (int) $b['level']),
                    'paragraph' => $pdf->paragraph((string) $b['text'], (string) $b['style']),
                    'kv' => $pdf->keyValues($b['rows']),
                    'table' => $pdf->table($b['columns'], $b['rows']),
                    'pagebreak' => $pdf->pageBreak(),
                    default => null,
                };
            }

            return RenderedArtifact::forFormat($pdf->output(), 'pdf');
        } catch (ReportRenderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ReportRenderException('render_failed', 'Falha ao gerar o PDF.');
        }
    }
}
