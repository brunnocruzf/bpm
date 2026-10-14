<?php

namespace App\Reports;

use App\Audit\AuditContext;
use App\Branding\Contrast;
use App\Jobs\GenerateReport;
use App\Models\BrandingAsset;
use App\Models\ProcessInstance;
use App\Models\ProcessStageVersion;
use App\Models\ReportJob;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Settings\SettingsRepository;
use App\Settings\Types\AuditRetentionSettings;
use App\Settings\Types\BrandingSettings;
use App\Settings\Types\ReportSettings;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use App\Support\Correlation;
use App\Support\Masker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Orquestra relatórios: solicitação idempotente (job na fila "reports"), geração assíncrona com
 * limites de tamanho/tempo e retry seguro, artefato em storage privado com expiração, download
 * somente via Laravel (autorização revalidada) e auditoria de geração/visualização/impressão.
 */
class ReportService
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly InstanceReportBuilder $instanceBuilder,
        private readonly OperationalReports $operational,
    ) {}

    /** @param array<string,mixed> $input */
    public function requestInstanceReport(ProcessInstance $instance, User $user, array $input, ?string $idempotencyKey): ReportJob
    {
        Gate::forUser($user)->authorize('createInstanceReport', [ReportJob::class, $instance]);
        $mode = (string) ($input['mode'] ?? 'full');
        if (! in_array($mode, ['summary', 'stages', 'full'], true)) {
            throw ApiProblem::validation(['mode' => ['Escolha resumo, etapas selecionadas ou completo.']]);
        }
        $stages = [];
        if ($mode === 'stages') {
            $valid = ProcessStageVersion::where('definition_version_id', $instance->definition_version_id)->where('stage_type', ProcessStageVersion::USER_TASK)->pluck('stage_key')->all();
            $stages = array_values(array_unique(array_filter(array_map('strval', (array) ($input['stages'] ?? [])), fn ($k) => in_array($k, $valid, true))));
            if ($stages === []) {
                throw ApiProblem::validation(['stages' => ['Selecione ao menos uma etapa do fluxo.']]);
            }
        }

        return $this->create('instance', ['mode' => $mode, 'stages' => $stages], 'pdf', $user, $idempotencyKey, $instance);
    }

    /** @param array<string,mixed> $input */
    public function requestOperational(string $type, User $user, array $input, string $format, ?string $idempotencyKey): ReportJob
    {
        Gate::forUser($user)->authorize('createOperational', ReportJob::class);
        if (! array_key_exists($type, OperationalReports::TYPES)) {
            throw ApiProblem::validation(['type' => ['Relatório desconhecido.']]);
        }
        if (! in_array($format, ['pdf', 'csv', 'xlsx'], true)) {
            throw ApiProblem::validation(['format' => ['Formato não permitido.']]);
        }

        return $this->create($type, ['filters' => $this->operational->validateFilters((array) ($input['filters'] ?? []))], $format, $user, $idempotencyKey);
    }

    /** @param array<string,mixed> $params */
    private function create(string $type, array $params, string $format, User $user, ?string $key, ?ProcessInstance $instance = null): ReportJob
    {
        if ($key !== null && ! preg_match('/^[A-Za-z0-9\-_.:]{8,160}$/', $key)) {
            throw ApiProblem::validation(['Idempotency-Key' => ['Chave de idempotência inválida.']]);
        }
        if ($key && ($existing = ReportJob::where('requested_by', $user->id)->where('idempotency_key', $key)->first())) {
            return $existing;
        }
        $template = $this->activeTemplate($instance ? 'instance' : 'operational');

        return DB::transaction(function () use ($type, $params, $format, $user, $key, $instance, $template) {
            $job = ReportJob::create([
                'report_type' => $type, 'template_id' => $template?->id, 'template_version' => $template?->version,
                'process_instance_id' => $instance?->id, 'parameters' => $params, 'format' => $format,
                'requested_by' => $user->id, 'requested_by_name' => $user->name, 'idempotency_key' => $key,
                'correlation_id' => Correlation::id(),
            ]);
            AuditLogger::record('report.requested', 'report_job', $job->id, ['type' => $type, 'format' => $format, 'parameters' => $params, 'template' => $template ? $template->key.' v'.$template->version : null], $instance?->id, $user);
            GenerateReport::dispatch($job->id)->onQueue('reports')->afterCommit();

            return $job;
        });
    }

    public function activeTemplate(string $kind): ?ReportTemplate
    {
        return ReportTemplate::where('kind', $kind)->where('status', 'active')->orderByDesc('activated_at')->first();
    }

    public function generate(string $jobId): void
    {
        $job = $this->claim($jobId);
        if (! $job) {
            return;
        }
        Correlation::set($job->correlation_id);
        AuditContext::as('worker:relatórios', fn () => $this->render($job), $job->id);
    }

    private function render(ReportJob $job): void
    {
        $started = microtime(true);
        try {
            $user = User::findOrFail($job->requested_by);
            $template = $job->template_id ? ReportTemplate::find($job->template_id) : null;
            $max = (int) config('bpm.reports.max_rows', 20000);
            if ($job->process_instance_id) {
                $instance = ProcessInstance::findOrFail($job->process_instance_id);
                // revalida a autorização no momento da geração
                if (! Gate::forUser($user)->allows('createInstanceReport', [ReportJob::class, $instance])) {
                    throw new ReportRenderException('forbidden', 'O solicitante não tem mais acesso a esta solicitação.');
                }
                $doc = $this->instanceBuilder->build($instance, $user, (array) $job->parameters);
            } else {
                $doc = $this->operational->build($job->report_type, (array) ($job->parameters['filters'] ?? []), $max);
            }
            $job->update(['progress' => 60]);
            $renderer = $this->renderer($template);
            if (! $renderer->supports($job->format)) {
                $renderer = app(NativeReportRenderer::class);
            }
            $artifact = $renderer->render($doc, $job->format, $template, $this->branding($user));
            if (strlen($artifact->bytes) > (int) config('bpm.reports.max_bytes')) {
                throw new ReportRenderException('too_large', 'Relatório acima do tamanho máximo; refine os filtros.');
            }
            if ((microtime(true) - $started) > (int) config('bpm.reports.timeout', 120)) {
                throw new ReportRenderException('render_timeout', 'Tempo máximo de geração excedido.', true);
            }
            $disk = (string) config('bpm.reports.disk');
            $key = 'reports/'.now()->format('Y/m').'/'.$job->id.'.'.$artifact->extension;
            Storage::disk($disk)->put($key, $artifact->bytes);
            $hours = (int) ($this->settings->value(app(AuditRetentionSettings::class))['reportArtifactHours'] ?? 24);
            $job->update([
                'status' => ReportJob::READY, 'progress' => 100, 'renderer' => $renderer->name(),
                'artifact_disk' => $disk, 'artifact_key' => $key, 'artifact_name' => $doc->fileName.'.'.$artifact->extension,
                'artifact_type' => $artifact->contentType, 'artifact_size' => strlen($artifact->bytes), 'artifact_sha256' => hash('sha256', $artifact->bytes),
                'row_count' => $doc->table ? count($doc->table['rows']) : null, 'finished_at' => now(), 'expires_at' => now()->addHours($hours),
                'error_code' => null, 'error_message' => null,
            ]);
            AuditLogger::record('report.generated', 'report_job', $job->id, ['type' => $job->report_type, 'format' => $job->format, 'renderer' => $renderer->name(), 'size' => strlen($artifact->bytes), 'sha256' => substr(hash('sha256', $artifact->bytes), 0, 16)], $job->process_instance_id);
        } catch (ReportRenderException $e) {
            $this->fail($job, $e->errorCode, $e->getMessage(), $e->transient);
        } catch (Throwable $e) {
            report($e);
            $this->fail($job, 'render_failed', 'Falha inesperada ao gerar o relatório (consulte o correlation ID).', false, Masker::error($e));
        }
    }

    private function fail(ReportJob $job, string $code, string $message, bool $transient, ?string $internal = null): void
    {
        if ($transient && $job->attempts < 3) {
            $job->update(['status' => ReportJob::QUEUED, 'error_code' => $code, 'error_message' => $message]);
            GenerateReport::dispatch($job->id)->onQueue('reports')->delay(now()->addSeconds(30 * $job->attempts));

            return;
        }
        $job->update(['status' => ReportJob::FAILED, 'error_code' => $code, 'error_message' => mb_substr($message, 0, 500), 'finished_at' => now()]);
        AuditLogger::record('report.failed', 'report_job', $job->id, ['code' => $code, 'internal' => $internal], $job->process_instance_id, outcome: 'failure');
    }

    private function claim(string $id): ?ReportJob
    {
        $n = ReportJob::whereKey($id)->where('status', ReportJob::QUEUED)
            ->update(['status' => ReportJob::RENDERING, 'attempts' => DB::raw('attempts + 1'), 'started_at' => now(), 'progress' => 10, 'updated_at' => now()]);

        return $n === 1 ? ReportJob::find($id) : null;
    }

    public function renderer(?ReportTemplate $template): ReportRenderer
    {
        $cfg = $this->settings->value(app(ReportSettings::class));
        if ($template?->engine === 'carbone' && $cfg['renderer'] === 'carbone' && config('bpm.reports.carbone.enabled')) {
            return app(CarboneReportRenderer::class);
        }

        return app(NativeReportRenderer::class);
    }

    /** @return array{org:string, brandColor:string, confidentiality:?string, footer:string, logo:?string} */
    public function branding(User $user): array
    {
        $b = $this->settings->value(app(BrandingSettings::class));
        $r = $this->settings->value(app(ReportSettings::class));
        $logo = null;
        if ($id = $b['logos']['main'] ?? null) {
            $asset = BrandingAsset::find($id);
            $logo = $asset ? Storage::disk($asset->disk)->get($asset->object_key) : null;
        }
        $tz = (string) config('bpm.sequences.timezone');

        return [
            'org' => (string) $b['orgName'].' — '.$b['appName'],
            // títulos/linhas do PDF ficam sobre branco: usa o tom derivado acessível da primária
            'brandColor' => Contrast::darkenFor((string) $b['colors']['primary']),
            'confidentiality' => $r['confidentialityLabel'] ?: null,
            'footer' => 'Gerado em '.now($tz)->format('d/m/Y H:i')." ($tz) por {$user->name}. Documento gerado a partir dos registros do BPM; conferir a versão vigente no sistema.",
            'logo' => $logo,
        ];
    }

    /** Download/visualização/impressão: revalida autorização e audita. */
    public function download(ReportJob $job, User $user, string $purpose): StreamedResponse
    {
        Gate::forUser($user)->authorize('download', $job);
        if ($job->expires_at && $job->expires_at->isPast()) {
            throw new ApiProblem(410, 'Arquivo expirado', 'O arquivo deste relatório expirou. Gere novamente.', 'report_expired');
        }
        $disk = Storage::disk((string) $job->artifact_disk);
        if (! $disk->exists((string) $job->artifact_key)) {
            throw new ApiProblem(410, 'Arquivo indisponível', 'O arquivo não está mais disponível. Gere novamente.', 'report_expired');
        }
        AuditLogger::record($purpose === 'print' ? 'report.printed' : 'report.downloaded', 'report_job', $job->id, ['purpose' => $purpose, 'format' => $job->format], $job->process_instance_id, $user);
        $inline = in_array($purpose, ['preview', 'print'], true) && $job->format === 'pdf';

        return $disk->response((string) $job->artifact_key, (string) $job->artifact_name, [
            'Content-Type' => (string) $job->artifact_type,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    /** Expira artefatos vencidos (agendador). */
    public function expire(): int
    {
        $n = 0;
        foreach (ReportJob::where('status', ReportJob::READY)->where('expires_at', '<', now())->limit(500)->get() as $job) {
            if ($job->artifact_disk && $job->artifact_key) {
                Storage::disk($job->artifact_disk)->delete($job->artifact_key);
            }
            $job->update(['status' => ReportJob::EXPIRED]);
            AuditContext::as('agendador', fn () => AuditLogger::record('report.expired', 'report_job', $job->id, [], $job->process_instance_id));
            $n++;
        }

        return $n;
    }
}
