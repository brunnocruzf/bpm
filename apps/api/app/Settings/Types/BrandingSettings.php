<?php

namespace App\Settings\Types;

use App\Branding\Contrast;
use App\Models\AppSetting;
use App\Models\BrandingAsset;
use App\Models\User;
use App\Settings\SettingType;
use App\Support\ApiProblem;

/**
 * Identidade visual da organização TMSA (sem multi-tenancy): nome, logos/favicon (assets
 * versionados no storage) e cores de marca. Cores de semântica de estado (sucesso/alerta/erro)
 * não são configuráveis. Combinações sem contraste WCAG mínimo são recusadas.
 */
class BrandingSettings extends SettingType
{
    public const KEY = 'branding';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Organização e identidade visual';
    }

    public function defaults(): array
    {
        $d = (array) config('bpm.branding.defaults');

        return [
            'appName' => $d['appName'],
            'orgName' => $d['orgName'],
            'tagline' => $d['tagline'],
            'colors' => $d['colors'],
            'logos' => ['main' => null, 'compact' => null, 'favicon' => null],
            // links institucionais da tela de login (vazio = não exibido)
            'links' => ['support' => null, 'privacy' => null, 'help' => null],
        ];
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array{support: ?string, privacy: ?string, help: ?string}
     */
    public static function links(array $value): array
    {
        $links = (array) ($value['links'] ?? []);

        return ['support' => $links['support'] ?? null, 'privacy' => $links['privacy'] ?? null, 'help' => $links['help'] ?? null];
    }

    public function normalize(array $input, array $current, User $by): array
    {
        $errors = [];
        $v = $current;
        foreach (['appName' => 60, 'orgName' => 80, 'tagline' => 80] as $f => $max) {
            if (array_key_exists($f, $input)) {
                $text = trim(strip_tags((string) $input[$f]));
                if ($text === '' && $f !== 'tagline') {
                    $errors[$f][] = 'Campo obrigatório.';
                }
                if (mb_strlen($text) > $max) {
                    $errors[$f][] = "Máximo de $max caracteres.";
                }
                $v[$f] = $text;
            }
        }
        if (isset($input['colors']) && is_array($input['colors'])) {
            foreach (['primary', 'secondary', 'accent'] as $c) {
                if (array_key_exists($c, $input['colors'])) {
                    $hex = strtolower(trim((string) $input['colors'][$c]));
                    if (! Contrast::isHex($hex)) {
                        $errors["colors.$c"][] = 'Use cor hexadecimal #rrggbb.';
                    }
                    $v['colors'][$c] = $hex;
                }
            }
            if (! $errors) {
                foreach (Contrast::check($v['colors']) as $row) {
                    if (! $row['ok']) {
                        $errors['colors.'.$row['token']][] = "Contraste insuficiente ({$row['ratio']}:1; mínimo {$row['required']}:1 para {$row['pair']}).";
                    }
                }
            }
        }
        if (isset($input['links']) && is_array($input['links'])) {
            $v['links'] = self::links($v);
            foreach (['support', 'privacy', 'help'] as $l) {
                if (! array_key_exists($l, $input['links'])) {
                    continue;
                }
                $url = trim((string) $input['links'][$l]);
                // somente https:// ou mailto: (sem javascript:, data:, http em claro)
                $ok = $url === '' || (mb_strlen($url) <= 300 && (
                    (str_starts_with(strtolower($url), 'https://') && filter_var($url, FILTER_VALIDATE_URL))
                    || preg_match('/^mailto:[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/i', $url)));
                if (! $ok) {
                    $errors["links.$l"][] = 'Use um endereço https:// ou mailto: válido.';
                }
                $v['links'][$l] = $url === '' ? null : $url;
            }
        }
        if (isset($input['logos']) && is_array($input['logos'])) {
            foreach (['main' => 'logo_main', 'compact' => 'logo_compact', 'favicon' => 'favicon'] as $slot => $kind) {
                if (! array_key_exists($slot, $input['logos'])) {
                    continue;
                }
                $id = $input['logos'][$slot];
                if ($id === null || $id === '') {
                    $v['logos'][$slot] = null;

                    continue;
                }
                $asset = BrandingAsset::where('id', (string) $id)->where('kind', $kind)->first();
                if (! $asset || $asset->av_status === 'infected') {
                    $errors["logos.$slot"][] = 'Imagem inexistente ou de outro tipo.';

                    continue;
                }
                $v['logos'][$slot] = $asset->id;
            }
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $v;
    }

    public function statusAfterSave(array $value): array
    {
        return [AppSetting::CONFIGURED, null];
    }

    public function present(array $value): array
    {
        $value['links'] = self::links($value);
        $value['contrast'] = Contrast::check($value['colors']);
        $value['derived'] = Contrast::derived($value['colors']);
        $value['assets'] = [];
        foreach ((array) $value['logos'] as $slot => $id) {
            $asset = $id ? BrandingAsset::find($id) : null;
            $value['assets'][$slot] = $asset ? ['id' => $asset->id, 'width' => $asset->width, 'height' => $asset->height, 'type' => $asset->content_type, 'version' => substr($asset->sha256, 0, 12)] : null;
        }
        $value['limits'] = config('bpm.branding.limits');

        return $value;
    }
}
