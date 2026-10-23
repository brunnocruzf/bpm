<?php

namespace App\Notifications;

use App\Branding\Contrast;
use App\Models\BrandingAsset;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Renderiza e-mails em HTML de tabela, compatível com Outlook e clientes móveis.
 * Usa o serviço Carbone já empregado por relatórios quando habilitado; o fallback mantém
 * o mesmo template seguro disponível em instalações sem licença/serviço Carbone.
 */
final class EmailLayoutRenderer
{
    /** @param array<string,mixed> $layout @param array<string,mixed> $branding */
    public function render(string $event, string $subject, string $text, array $layout, array $branding): string
    {
        return $this->renderDocument($event, $subject, $text, $layout, $branding)['html'];
    }

    /** @param array<string,mixed> $layout @param array<string,mixed> $branding @return array{html:string,renderer:string} */
    public function preview(string $sample, array $layout, array $branding): array
    {
        $sample = array_key_exists($sample, EmailLayout::defaultTexts())
            ? $sample
            : 'task.assigned';
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $org = (string) config('bpm.organization.name');
        $app = (string) config('app.name');
        $examples = [
            'task.assigned' => ['subject' => "[$org] Aprovar recebimento · IRR-000123/26", 'text' => "Olá, Ana.\n\nA solicitação IRR-000123/26 (Recebimento de materiais) chegou à etapa \"Aprovação do recebimento\" e aguarda sua ação.\nPrazo: 16/10/2026 17:00\n\nAbra a tarefa (login necessário): $base/tasks/preview-task"],
            'stage.completed' => ['subject' => "[$org] IRR-000123/26: etapa concluída", 'text' => "A etapa \"Conferência inicial\" da solicitação IRR-000123/26 (Recebimento de materiais) foi concluída.\n\nAcompanhe (login necessário): $base/instances/preview-instance"],
            'identity.invitation' => ['subject' => "Ative sua conta $app", 'text' => "Olá, Bruno.\n\nSeu acesso ao $app foi preparado. Este convite é válido por 60 minutos e pode ser usado uma única vez.\n\nUse o botão para ativar sua conta: $base/invite/preview-token"],
            'identity.password_reset' => ['subject' => "Redefina sua senha $app", 'text' => "Recebemos uma solicitação para redefinir sua senha. O link é válido por 60 minutos e pode ser usado uma única vez.\n\nContinue com segurança: $base/password/reset/preview-token"],
            'instance.ended' => ['subject' => "[$org] IRR-000123/26 encerrada", 'text' => "A solicitação IRR-000123/26 (Recebimento de materiais) foi encerrada.\n\nConsulte (login necessário): $base/instances/preview-instance"],
            'admin.alert' => ['subject' => "[$app] Atenção necessária", 'text' => "Uma verificação operacional precisa de atenção da equipe administradora.\n\nConsulte o painel do BPM: $base/settings"],
            'email.test' => ['subject' => "[$org] E-mail de teste", 'text' => "Este é um e-mail de teste enviado pela configuração de notificações.\n\nSe recebeu esta mensagem, o envio está funcionando.\n\nAcesse o BPM: $base"],
        ];
        $example = $examples[$sample];

        return $this->renderDocument($sample, $example['subject'], $example['text'], $layout, $branding);
    }

    /** @param array<string,mixed> $layout @param array<string,mixed> $branding @return array{html:string,renderer:string} */
    private function renderDocument(string $event, string $subject, string $text, array $layout, array $branding): array
    {
        $layout = array_replace(EmailLayout::defaults(), $layout);
        $accent = (string) $layout['accentColor'];
        if (! Contrast::isHex($accent) || Contrast::bestForeground($accent)[1] < Contrast::TEXT_MIN) {
            $accent = EmailLayout::defaults()['accentColor'];
        }
        $eventTexts = array_replace(
            ['eyebrow' => 'Notificação do processo', 'headline' => 'Atualização do BPM', 'actionLabel' => 'Abrir o BPM', 'bodyIntro' => ''],
            EmailLayout::defaultTexts()[$event] ?? [],
            (array) ($layout['texts'][$event] ?? []),
        );
        $appUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        [$actionUrl, $content] = $this->extractAction($text, $appUrl);
        $bodyIntro = trim(strip_tags((string) ($eventTexts['bodyIntro'] ?? '')));
        if ($bodyIntro !== '') {
            $content = $bodyIntro."\n\n".$content;
        }
        $orgName = $this->plainText((string) ($branding['orgName'] ?? config('bpm.organization.name')));
        $data = [
            'preheader' => $this->plainText($subject),
            'headerTitle' => $this->plainText((string) $layout['headerTitle']),
            'orgName' => $orgName,
            'logoHtml' => $this->logoHtml($branding, $appUrl, $orgName, (string) $layout['headerTitle']),
            'accentColor' => $accent,
            'accentTextColor' => Contrast::bestForeground($accent)[0],
            'eyebrow' => $this->plainText((string) $eventTexts['eyebrow']),
            'headline' => $this->plainText((string) $eventTexts['headline']),
            'subject' => $this->plainText($subject),
            'contentHtml' => $this->contentHtml($content),
            'actionUrl' => $actionUrl ?: $appUrl,
            'actionLabel' => $this->plainText((string) $eventTexts['actionLabel']),
            'footerText' => $this->plainText((string) $layout['footerText']),
            'linkHelpText' => $this->plainText((string) $layout['linkHelpText']),
            'year' => date('Y'),
            'appName' => $this->plainText((string) config('app.name')),
        ];
        $template = (string) file_get_contents(resource_path('email/notification.html'));
        $carbone = $this->renderWithCarbone($template, $data);

        return $carbone !== null
            ? ['html' => $carbone, 'renderer' => 'carbone']
            : ['html' => $this->renderLocally($template, $data), 'renderer' => 'native'];
    }

    /** @return array{?string,string} */
    private function extractAction(string $text, string $appUrl): array
    {
        $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        preg_match_all('~https?://[^\s<>"\']+~iu', $text, $matches);
        foreach ($matches[0] as $candidate) {
            $url = rtrim((string) $candidate, '.,;:!?)]}');
            $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '' && $urlHost === $host && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $text = str_replace($candidate, '', $text);

                return [$url, trim($text)];
            }
        }

        return [null, trim($text)];
    }

    private function contentHtml(string $text): string
    {
        $paragraphs = preg_split('/\R\s*\R/u', trim($text)) ?: [];
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), fn ($p) => $p !== ''));
        if ($paragraphs === []) {
            return '';
        }
        $out = [];
        foreach ($paragraphs as $paragraph) {
            $out[] = '<p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65;color:#334155;">'.nl2br(e($paragraph), false).'</p>';
        }

        return implode('', $out);
    }

    private function logoHtml(array $branding, string $appUrl, string $orgName, string $headerTitle): string
    {
        $id = (string) ($branding['logos']['main'] ?? '');
        $asset = $id !== ''
            ? BrandingAsset::query()->whereKey($id)->where('kind', 'logo_main')->where('av_status', '!=', 'infected')->first()
            : null;
        if (! $asset) {
            return '<span style="font-family:Arial,Helvetica,sans-serif;font-size:20px;line-height:1.2;font-weight:700;color:#172230;">'.e($headerTitle).'</span>';
        }

        $url = $appUrl.'/api/v1/public/branding/assets/'.$asset->id.'?v='.substr($asset->sha256, 0, 12);

        return '<img src="'.e($url).'" alt="'.e('Logo '.$orgName).'" height="48" style="display:block;height:48px;max-width:190px;width:auto;border:0;outline:none;text-decoration:none;">';
    }

    private function renderWithCarbone(string $template, array $data): ?string
    {
        $cfg = (array) config('bpm.reports.carbone');
        if (empty($cfg['enabled'])) {
            return null;
        }
        try {
            $tokenFile = $cfg['token_file'] ?? null;
            $token = $tokenFile && is_readable((string) $tokenFile) ? trim((string) file_get_contents((string) $tokenFile)) : null;
            $carboneData = [];
            foreach ($data as $key => $value) {
                $carboneData[$key] = in_array($key, ['contentHtml', 'logoHtml'], true)
                    ? (string) $value
                    : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $request = Http::timeout(min(30, (int) ($cfg['timeout'] ?? 15)))->connectTimeout(5)
                ->withHeaders(['carbone-version' => '5'])
                ->withBody((string) json_encode([
                    'data' => $carboneData,
                    'template' => base64_encode($template),
                    'convertTo' => 'html',
                    'reportName' => 'bpm-email',
                    'lang' => 'pt-br',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json');
            if ($token) {
                $request = $request->withToken($token);
            }
            $response = $request->post(rtrim((string) ($cfg['base_url'] ?? 'http://carbone:4000'), '/').'/render/template?download=true');
            $html = $response->body();
            if (! $response->successful() || $html === '' || strlen($html) > 512 * 1024 || ! str_contains(strtolower($html), '<html')) {
                Log::warning('Carbone não renderizou o template de e-mail; usando o template HTML local.', ['status' => $response->status()]);

                return null;
            }

            return $html;
        } catch (ConnectionException $e) {
            Log::warning('Carbone indisponível para renderizar e-mail; usando o template HTML local.', ['exception' => $e::class]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Falha no renderizador Carbone para e-mail; usando o template HTML local.', ['exception' => $e::class]);

            return null;
        }
    }

    private function renderLocally(string $template, array $data): string
    {
        $rawKeys = ['contentHtml', 'logoHtml'];
        foreach ($data as $key => $value) {
            $replacement = in_array($key, $rawKeys, true) ? (string) $value : e((string) $value);
            $template = str_replace('{d.'.$key.(in_array($key, $rawKeys, true) ? ':html' : '').'}', $replacement, $template);
        }

        return $template;
    }

    private function plainText(string $value): string
    {
        return trim(strip_tags(str_replace(["\r", "\n"], ' ', $value)));
    }
}
