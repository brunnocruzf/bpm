<?php

namespace App\Reports;

use App\Models\ReportTemplate;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Modelos de relatório versionados. Nativos: opções declarativas (título, texto de cabeçalho,
 * confidencialidade). Carbone: arquivo DOCX/ODT/XLSX/ODS validado (sem macros, sem vínculos
 * externos, tamanho limitado), guardado em storage privado com hash. Ativar = nova versão vigente.
 */
class ReportTemplateService
{
    public const DEFAULTS = [
        ['key' => 'instancia_padrao', 'name' => 'Relatório da solicitação (padrão)', 'kind' => 'instance', 'options' => ['title' => 'Relatório da solicitação', 'confidential' => true]],
        ['key' => 'operacional_padrao', 'name' => 'Relatórios operacionais (padrão)', 'kind' => 'operational', 'options' => ['title' => 'Relatório operacional', 'confidential' => true]],
    ];

    public function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $d) {
            if (! ReportTemplate::where('key', $d['key'])->exists()) {
                ReportTemplate::create([...$d, 'version' => 1, 'engine' => 'native', 'status' => 'active', 'activated_at' => now(), 'created_by_name' => 'sistema']);
            }
        }
    }

    /** @param array<string,mixed> $input */
    public function createVersion(array $input, ?UploadedFile $file, User $by): ReportTemplate
    {
        $key = (string) ($input['key'] ?? '');
        if (! preg_match('/^[a-z][a-z0-9_]{2,59}$/', $key)) {
            throw ApiProblem::validation(['key' => ['Chave em minúsculas (a-z, 0-9, _).']]);
        }
        $kind = (string) ($input['kind'] ?? '');
        $engine = (string) ($input['engine'] ?? 'native');
        if (! in_array($kind, ['instance', 'operational'], true) || ! in_array($engine, ['native', 'carbone'], true)) {
            throw ApiProblem::validation(['kind' => ['Tipo/engine inválidos.']]);
        }
        $options = [
            'title' => mb_substr(strip_tags((string) ($input['title'] ?? '')), 0, 120),
            'headerText' => mb_substr(strip_tags((string) ($input['headerText'] ?? '')), 0, 600),
            'confidential' => filter_var($input['confidential'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
        $fileAttrs = [];
        if ($engine === 'carbone') {
            if (! $file) {
                throw ApiProblem::validation(['file' => ['Modelo Carbone exige o arquivo (DOCX, ODT, XLSX ou ODS).']]);
            }
            $fileAttrs = $this->storeTemplateFile($file, $key);
        }

        return DB::transaction(function () use ($key, $kind, $engine, $options, $fileAttrs, $input, $by) {
            $version = (int) ReportTemplate::where('key', $key)->lockForUpdate()->max('version') + 1;
            $t = ReportTemplate::create([
                'key' => $key, 'version' => $version, 'name' => mb_substr(strip_tags((string) ($input['name'] ?? $key)), 0, 160),
                'kind' => $kind, 'engine' => $engine, 'status' => 'draft', 'options' => $options, ...$fileAttrs,
                'created_by' => $by->id, 'created_by_name' => $by->name,
            ]);
            AuditLogger::record('report_template.created', 'report_template', $t->id, ['key' => $key, 'version' => $version, 'engine' => $engine, 'sha256' => $t->file_sha256], null, $by, context: ['object_name' => $t->name]);

            return $t;
        });
    }

    public function activate(ReportTemplate $t, User $by): ReportTemplate
    {
        return DB::transaction(function () use ($t, $by) {
            ReportTemplate::where('kind', $t->kind)->where('status', 'active')->where('id', '!=', $t->id)->update(['status' => 'retired']);
            $t->update(['status' => 'active', 'activated_by' => $by->id, 'activated_at' => now()]);
            AuditLogger::record('report_template.activated', 'report_template', $t->id, ['key' => $t->key, 'version' => $t->version, 'kind' => $t->kind], null, $by, context: ['object_name' => $t->name]);

            return $t;
        });
    }

    /** @return array<string,mixed> */
    private function storeTemplateFile(UploadedFile $file, string $key): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['docx', 'odt', 'xlsx', 'ods'], true)) {
            throw ApiProblem::validation(['file' => ['Use DOCX, ODT, XLSX ou ODS (formatos com macro são recusados).']]);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            throw ApiProblem::validation(['file' => ['Modelo acima de 5 MB.']]);
        }
        $zip = new ZipArchive;
        if ($zip->open((string) $file->getRealPath()) !== true) {
            throw ApiProblem::validation(['file' => ['Arquivo não é um documento Office/ODF válido.']]);
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('#(vbaProject\.bin|^Basic/|^Scripts/|activeX|oleObject)#i', $name)) {
                $zip->close();
                throw ApiProblem::validation(['file' => ['Modelo contém macros, objetos ativos ou caminhos inválidos.']]);
            }
            if (str_ends_with($name, '.rels') && str_contains((string) $zip->getFromIndex($i), 'TargetMode="External"')) {
                $zip->close();
                throw ApiProblem::validation(['file' => ['Modelo contém vínculos externos (imagens/URLs remotas não são permitidas).']]);
            }
        }
        $zip->close();
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $disk = (string) config('bpm.reports.disk');
        $path = 'report-templates/'.$key.'/'.bin2hex(random_bytes(8)).'.'.$ext;
        Storage::disk($disk)->put($path, $bytes);

        return ['file_disk' => $disk, 'file_key' => $path, 'file_sha256' => hash('sha256', $bytes), 'file_size' => strlen($bytes)];
    }
}
