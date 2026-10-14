<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\AuthMethods;
use App\Auth\EntraAuthConfig;
use App\Branding\Contrast;
use App\Http\Controllers\Controller;
use App\Models\BrandingAsset;
use App\Settings\SettingsRepository;
use App\Settings\Types\BrandingSettings;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints públicos (antes do login): identidade visual aplicada e parâmetros públicos do login
 * (client ID/tenant/scope não são segredos). Nada de segredo, configuração interna ou PII.
 */
class PublicController extends Controller
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function branding(): JsonResponse
    {
        $b = $this->settings->value(app(BrandingSettings::class));
        $assets = [];
        foreach ((array) $b['logos'] as $slot => $id) {
            $asset = $id ? BrandingAsset::find($id) : null;
            $assets[$slot] = $asset ? '/api/v1/public/branding/assets/'.$asset->id.'?v='.substr($asset->sha256, 0, 12) : null;
        }
        $setting = $this->settings->get(BrandingSettings::KEY);

        return response()->json([
            'appName' => $b['appName'], 'orgName' => $b['orgName'], 'tagline' => $b['tagline'], 'colors' => $b['colors'], 'derived' => Contrast::derived($b['colors']), 'assets' => $assets,
            'links' => BrandingSettings::links($b),
            'version' => $setting->version,
        ])->header('Cache-Control', 'no-cache');
    }

    public function brandingAsset(string $id): Response
    {
        $b = $this->settings->value(app(BrandingSettings::class));
        // somente imagens da identidade visual aplicada (rascunhos exigem autenticação)
        if (! in_array($id, array_filter((array) $b['logos']), true)) {
            throw ApiProblem::notFound();
        }
        $asset = BrandingAsset::find($id) ?? throw ApiProblem::notFound();
        $bytes = Storage::disk($asset->disk)->get($asset->object_key) ?? throw ApiProblem::notFound();

        return response($bytes, 200, [
            'Content-Type' => $asset->content_type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * Métodos de login disponíveis para ESTA requisição (o SuperAdmin respeita a lista de IPs) e
     * parâmetros públicos do MSAL. A tela apenas reflete; o servidor é quem aceita ou recusa o token.
     */
    public function authConfig(Request $request, EntraAuthConfig $config): JsonResponse
    {
        return response()->json([
            'driver' => config('bpm.auth.driver'),
            'source' => $config->source(),
            'entra' => $config->spaConfig(),
            'methods' => [
                'entra' => AuthMethods::entraEnabled($config),
                'demo' => AuthMethods::demoEnabled(),
                'demoUrl' => AuthMethods::demoUrl(),
                'superadmin' => AuthMethods::superAdminEnabled($request),
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
