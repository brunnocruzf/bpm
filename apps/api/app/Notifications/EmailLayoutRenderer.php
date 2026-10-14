<?php

namespace App\Notifications;

use App\Branding\Contrast;
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
    /** @var array<string,array{eyebrow:string,headline:string,action:string}> */
    private const EVENTS = [
        'task.assigned' => ['eyebrow' => 'Ação necessária', 'headline' => 'Uma tarefa aguarda sua atenção', 'action' => 'Acessar tarefa'],
        'stage.completed' => ['eyebrow' => 'Atualização do processo', 'headline' => 'Etapa concluída', 'action' => 'Acompanhar solicitação'],
        'instance.ended' => ['eyebrow' => 'Processo finalizado', 'headline' => 'Solicitação encerrada', 'action' => 'Ver solicitação'],
        'identity.invitation' => ['eyebrow' => 'Acesso ao BPM', 'headline' => 'Você recebeu um convite', 'action' => 'Ativar minha conta'],
        'identity.password_reset' => ['eyebrow' => 'Segurança da conta', 'headline' => 'Redefina sua senha', 'action' => 'Criar nova senha'],
        'admin.alert' => ['eyebrow' => 'Operação do sistema', 'headline' => 'Alerta do BPM', 'action' => 'Abrir o BPM'],
        'email.test' => ['eyebrow' => 'Verificação de entrega', 'headline' => 'E-mail de teste', 'action' => 'Acessar o BPM'],
    ];

    /** @param array<string,mixed> $layout @param array<string,mixed> $branding */
    public function render(string $event, string $subject, string $text, array $layout, array $branding): string
    {
        return $this->renderDocument($event, $subject, $text, $layout, $branding)['html'];
    }

    /** @param array<string,mixed> $layout @param array<string,mixed> $branding @return array{html:string,renderer:string} */
    public function preview(string $sample, array $layout, array $branding): array
    {
        $sample = in_array($sample, ['task.assigned', 'stage.completed', 'identity.invitation', 'identity.password_reset', 'admin.alert'], true)
            ? $sample
            : 'task.assigned';
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $examples = [
            'task.assigned' => ['subject' => '[TMSA] Aprovar recebimento · IRR-000123/26', 'text' => "Olá, Ana.\n\nA solicitação IRR-000123/26 (Recebimento de materiais) chegou à etapa \"Aprovação do recebimento\" e aguarda sua ação.\nPrazo: 16/10/2026 17:00\n\nAbra a tarefa (login necessário): $base/tasks/preview-task"],
            'stage.completed' => ['subject' => '[TMSA] IRR-000123/26: etapa concluída', 'text' => "A etapa \"Conferência inicial\" da solicitação IRR-000123/26 (Recebimento de materiais) foi concluída.\n\nAcompanhe (login necessário): $base/instances/preview-instance"],
            'identity.invitation' => ['subject' => 'Ative sua conta BPM TMSA', 'text' => "Olá, Bruno.\n\nSeu acesso ao BPM TMSA foi preparado. Este convite é válido por 60 minutos e pode ser usado uma única vez.\n\nUse o botão para ativar sua conta: $base/invite/preview-token"],
            'identity.password_reset' => ['subject' => 'Redefina sua senha BPM TMSA', 'text' => "Recebemos uma solicitação para redefinir sua senha. O link é válido por 60 minutos e pode ser usado uma única vez.\n\nContinue com segurança: $base/password/reset/preview-token"],
            'admin.alert' => ['subject' => '[BPM TMSA] Atenção necessária', 'text' => "Uma verificação operacional precisa de atenção da equipe administradora.\n\nConsulte o painel do BPM: $base/settings"],
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
        $preset = self::EVENTS[$event] ?? ['eyebrow' => 'Notificação do processo', 'headline' => 'Atualização do BPM', 'action' => 'Abrir o BPM'];
        $appUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        [$actionUrl, $content] = $this->extractAction($text, $appUrl);
        $data = [
            'preheader' => $this->plainText($subject),
            'headerTitle' => $this->plainText((string) $layout['headerTitle']),
            'orgName' => $this->plainText((string) ($branding['orgName'] ?? 'TMSA')),
            'accentColor' => $accent,
            'accentTextColor' => Contrast::bestForeground($accent)[0],
            'eyebrow' => $preset['eyebrow'],
            'headline' => $preset['headline'],
            'subject' => $this->plainText($subject),
            'contentHtml' => $this->contentHtml($content),
            'actionUrl' => $actionUrl ?: $appUrl,
            'actionLabel' => $actionUrl ? $preset['action'] : 'Acessar o BPM',
            'footerText' => $this->plainText((string) $layout['footerText']),
            'year' => date('Y'),
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
        foreach ($matches[0] ?? [] as $candidate) {
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
                $carboneData[$key] = $key === 'contentHtml'
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
        $rawKeys = ['contentHtml'];
        foreach ($data as $key => $value) {
            $replacement = in_array($key, $rawKeys, true) ? (string) $value : e((string) $value);
            $template = str_replace('{d.'.$key.($key === 'contentHtml' ? ':html' : '').'}', $replacement, $template);
        }

        return $template;
    }

    private function plainText(string $value): string
    {
        return trim(strip_tags(str_replace(["\r", "\n"], ' ', $value)));
    }
}
