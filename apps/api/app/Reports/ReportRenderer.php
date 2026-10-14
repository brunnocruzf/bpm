<?php

namespace App\Reports;

use App\Models\ReportTemplate;

/**
 * Contrato de renderização (desacopla controllers/serviços do fornecedor). Implementações:
 * NativeReportRenderer (PDF/CSV/XLSX próprios) e CarboneReportRenderer (self-hosted, rede interna,
 * somente após aprovação de licença). Recebe apenas o DTO já autorizado/mascarado pelo Laravel.
 */
interface ReportRenderer
{
    public function name(): string;

    public function supports(string $format): bool;

    /**
     * @param  array{org:string, brandColor:string, confidentiality:?string, footer:string, logo:?string}  $branding
     *
     * @throws ReportRenderException
     */
    public function render(ReportDocument $doc, string $format, ?ReportTemplate $template, array $branding): RenderedArtifact;
}
