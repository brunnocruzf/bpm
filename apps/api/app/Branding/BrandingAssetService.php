<?php

namespace App\Branding;

use App\Models\BrandingAsset;
use App\Models\User;
use App\Runtime\VirusScanner;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Upload seguro de logos/favicon: PNG/WebP (e .ico para o favicon — ver IcoCodec); SVG recusado (sem sanitização de SVG
 * no MVP), validação por conteúdo (finfo + decodificação), limites de tamanho/dimensões,
 * antivírus e RECODIFICAÇÃO via GD (remove metadados e conteúdo anexado/poliglota).
 * Armazenamento no storage adotado, chave aleatória; servido pela própria aplicação.
 */
class BrandingAssetService
{
    public const KINDS = ['logo_main', 'logo_compact', 'favicon'];

    public function __construct(private readonly VirusScanner $scanner) {}

    public function upload(UploadedFile $file, string $kind, User $by): BrandingAsset
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw ApiProblem::validation(['kind' => ['Tipo de imagem inválido.']]);
        }
        $limits = (array) config("bpm.branding.limits.$kind");
        $errors = [];
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();
        if (! $file->isValid()) {
            throw ApiProblem::validation(['file' => ['Falha no envio do arquivo.']]);
        }
        if ($ext === 'svg' || str_contains($mime, 'svg')) {
            throw ApiProblem::validation(['file' => ['SVG não é aceito (conteúdo ativo). Envie PNG ou WebP.']]);
        }
        $icoMimes = ['image/vnd.microsoft.icon', 'image/x-icon', 'image/ico', 'image/icon', 'image/x-ico', 'application/ico'];
        $isIco = $ext === 'ico' || in_array($mime, $icoMimes, true);
        if ($isIco && $kind !== 'favicon') {
            throw ApiProblem::validation(['file' => ['Arquivo .ico só é aceito para o favicon.']]);
        }
        if ($isIco) {
            // assinatura binária do ICO (reservado = 0, tipo = 1); o conteúdo é validado pelo IcoCodec
            $magic = (string) file_get_contents((string) $file->getRealPath(), false, null, 0, 4);
            if ($ext !== 'ico' || ! in_array($mime, $icoMimes, true) || $magic !== "\x00\x00\x01\x00") {
                $errors['file'][] = 'Arquivo .ico inválido.';
            }
        } elseif (! in_array($ext, ['png', 'webp'], true) || ! in_array($mime, ['image/png', 'image/webp'], true)) {
            $errors['file'][] = $kind === 'favicon' ? 'Envie uma imagem PNG, WebP ou ICO.' : 'Envie uma imagem PNG ou WebP.';
        }
        if ($file->getSize() > $limits['max_kb'] * 1024) {
            $errors['file'][] = "Imagem acima de {$limits['max_kb']} KB.";
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }
        if ($isIco) {
            return $this->uploadIco($file, $limits, $by);
        }
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ApiProblem::validation(['file' => ['Arquivo não é uma imagem PNG/WebP válida.']]);
        }
        [$w, $h] = $info;
        if ($w < $limits['min_w'] || $w > $limits['max_w'] || $h < $limits['min_h'] || $h > $limits['max_h']) {
            throw ApiProblem::validation(['file' => ["Dimensões {$w}×{$h} fora do permitido ({$limits['min_w']}–{$limits['max_w']} × {$limits['min_h']}–{$limits['max_h']} px)."]]);
        }
        if (! empty($limits['square']) && abs($w - $h) > max($w, $h) * 0.1) {
            throw ApiProblem::validation(['file' => ['Esta imagem deve ser quadrada.']]);
        }
        $av = $this->scanner->scan($file->getRealPath());
        if ($av === 'infected') {
            throw ApiProblem::validation(['file' => ['Arquivo recusado pelo antivírus.']]);
        }

        $bytes = $this->reencode((string) file_get_contents($file->getRealPath()), $info[2]);
        $type = $info[2] === IMAGETYPE_WEBP && function_exists('imagewebp') ? 'image/webp' : 'image/png';
        $disk = (string) config('bpm.attachments.disk');
        $key = 'branding/'.$kind.'/'.Str::uuid().($type === 'image/webp' ? '.webp' : '.png');
        Storage::disk($disk)->put($key, $bytes);

        $asset = BrandingAsset::create([
            'kind' => $kind, 'disk' => $disk, 'object_key' => $key, 'content_type' => $type, 'size' => strlen($bytes),
            'width' => $w, 'height' => $h, 'sha256' => hash('sha256', $bytes), 'av_status' => $av, 'created_by' => $by->id, 'created_at' => now('UTC'),
        ]);
        AuditLogger::record('branding.asset_uploaded', 'branding_asset', $asset->id, ['kind' => $kind, 'size' => $asset->size, 'width' => $w, 'height' => $h, 'type' => $type, 'av' => $av], null, $by);

        return $asset;
    }

    /**
     * Favicon .ico: leitura estrita do contêiner e REGRAVAÇÃO como ICO novo com frames PNG
     * (16/32/48 px e o tamanho de origem até 256) — nada do arquivo original é servido.
     *
     * @param  array<string,mixed>  $limits
     */
    private function uploadIco(UploadedFile $file, array $limits, User $by): BrandingAsset
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new ApiProblem(503, 'Processamento de imagem indisponível', 'A extensão GD não está instalada no servidor.', 'image_processing_unavailable');
        }
        try {
            $frames = IcoCodec::decode((string) file_get_contents($file->getRealPath()));
        } catch (InvalidArgumentException $e) {
            throw ApiProblem::validation(['file' => ['Arquivo .ico inválido: '.$e->getMessage()]]);
        }
        $source = IcoCodec::largest($frames);
        [$w, $h] = [$source['width'], $source['height']];
        if ($w < $limits['min_w'] || $w > $limits['max_w'] || $h < $limits['min_h'] || $h > $limits['max_h']) {
            throw ApiProblem::validation(['file' => ["Dimensões {$w}×{$h} fora do permitido ({$limits['min_w']}–{$limits['max_w']} px)."]]);
        }
        if (abs($w - $h) > max($w, $h) * 0.1) {
            throw ApiProblem::validation(['file' => ['O favicon deve ser quadrado.']]);
        }
        $av = $this->scanner->scan($file->getRealPath());
        if ($av === 'infected') {
            throw ApiProblem::validation(['file' => ['Arquivo recusado pelo antivírus.']]);
        }
        $bytes = IcoCodec::encode($source['image']);
        foreach ($frames as $f) {
            imagedestroy($f['image']);
        }
        $disk = (string) config('bpm.attachments.disk');
        $key = 'branding/favicon/'.Str::uuid().'.ico';
        Storage::disk($disk)->put($key, $bytes);
        $asset = BrandingAsset::create([
            'kind' => 'favicon', 'disk' => $disk, 'object_key' => $key, 'content_type' => 'image/x-icon', 'size' => strlen($bytes),
            'width' => $w, 'height' => $h, 'sha256' => hash('sha256', $bytes), 'av_status' => $av, 'created_by' => $by->id, 'created_at' => now('UTC'),
        ]);
        AuditLogger::record('branding.asset_uploaded', 'branding_asset', $asset->id, ['kind' => 'favicon', 'size' => $asset->size, 'width' => $w, 'height' => $h, 'type' => 'image/x-icon', 'frames' => count($frames), 'av' => $av], null, $by);

        return $asset;
    }

    public function contents(BrandingAsset $asset): ?string
    {
        return Storage::disk($asset->disk)->get($asset->object_key);
    }

    private function reencode(string $bytes, int $type): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new ApiProblem(503, 'Processamento de imagem indisponível', 'A extensão GD não está instalada no servidor.', 'image_processing_unavailable');
        }
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            throw ApiProblem::validation(['file' => ['Não foi possível decodificar a imagem.']]);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);
        ob_start();
        $ok = $type === IMAGETYPE_WEBP && function_exists('imagewebp') ? imagewebp($img, null, 90) : imagepng($img, null, 9);
        $out = (string) ob_get_clean();
        imagedestroy($img);
        if (! $ok || $out === '') {
            throw ApiProblem::validation(['file' => ['Falha ao processar a imagem.']]);
        }

        return $out;
    }
}
