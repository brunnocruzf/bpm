<?php

namespace App\Notifications;

use App\Support\ApiProblem;

/**
 * Template de e-mail com placeholders documentados e fechados — sem PHP/JS/Liquid, sem expressões.
 * O texto é tratado como texto puro; o HTML é gerado escapando tudo. Nenhum valor de formulário
 * entra no e-mail (somente resumo mínimo e link autenticado).
 */
final class NotificationTemplate
{
    public const PLACEHOLDERS = [
        'organizacao' => 'Nome da organização',
        'fluxo' => 'Nome do fluxo',
        'protocolo' => 'Protocolo da solicitação (ex.: IRR-000001/26)',
        'etapa' => 'Nome da etapa que recebeu a tarefa',
        'grupo' => 'Grupo/raia responsável',
        'responsavel' => 'Nome do destinatário',
        'prazo' => 'Prazo da tarefa (se configurado)',
        'link' => 'Link protegido da tarefa (exige login)',
    ];

    public const DEFAULT_SUBJECT = '[{{organizacao}}] Nova tarefa: {{etapa}} — {{protocolo}}';

    public const DEFAULT_BODY = "Olá, {{responsavel}}.\n\nA solicitação {{protocolo}} ({{fluxo}}) chegou à etapa \"{{etapa}}\" e aguarda ação de {{grupo}}.\nPrazo: {{prazo}}\n\nAbra a tarefa (login necessário): {{link}}\n\nMensagem automática do BPM. Os dados do formulário não são enviados por e-mail.";

    /**
     * @return array{subject:string, body:string}
     *
     * @throws ApiProblem
     */
    public static function validate(?string $subject, ?string $body): array
    {
        $subject = trim(strip_tags((string) ($subject ?: self::DEFAULT_SUBJECT)));
        $body = trim(strip_tags((string) ($body ?: self::DEFAULT_BODY)));
        $errors = [];
        if (mb_strlen($subject) > 200) {
            $errors['notifyAssignee.subject'][] = 'Assunto com no máximo 200 caracteres.';
        }
        if (mb_strlen($body) > 4000) {
            $errors['notifyAssignee.body'][] = 'Corpo com no máximo 4000 caracteres.';
        }
        foreach (['subject' => $subject, 'body' => $body] as $field => $text) {
            preg_match_all('/\{\{\s*([^}]*)\s*\}\}/', $text, $m);
            foreach ($m[1] as $name) {
                if (! array_key_exists(trim($name), self::PLACEHOLDERS)) {
                    $errors["notifyAssignee.$field"][] = 'Placeholder não permitido: {{'.trim($name).'}}';
                }
            }
            if (preg_match('/\{%|<\?|\$\{|<script/i', $text)) {
                $errors["notifyAssignee.$field"][] = 'Código/expressões não são permitidos no template.';
            }
        }
        if (! str_contains($body, '{{link}}')) {
            $errors['notifyAssignee.body'][] = 'O corpo precisa conter {{link}} (acesso autenticado à tarefa).';
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /** @param array<string,string|null> $vars */
    public static function render(string $template, array $vars, bool $singleLine = false): string
    {
        $out = (string) preg_replace_callback('/\{\{\s*([a-z]+)\s*\}\}/', function ($m) use ($vars, $singleLine) {
            $value = (string) ($vars[$m[1]] ?? '—');
            $value = strip_tags($value);

            return $singleLine ? preg_replace('/[\r\n]+/', ' ', $value) : $value;
        }, $template);

        return $singleLine ? mb_substr(trim((string) preg_replace('/[\r\n]+/', ' ', $out)), 0, 200) : $out;
    }

    /** HTML simples e seguro (tudo escapado; links só para a própria aplicação). */
    public static function html(string $text, string $appName, string $appUrl): string
    {
        $escaped = nl2br(e($text), false);
        $base = preg_quote(e(rtrim($appUrl, '/')), '#');
        $escaped = (string) preg_replace_callback('#('.$base.'/[A-Za-z0-9/_\-?=&;.%]*)#', fn ($m) => '<a href="'.$m[1].'">'.$m[1].'</a>', $escaped);
        $name = e($appName);

        return '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#f4f7f9;font-family:Segoe UI,Arial,sans-serif;color:#172230">'
            .'<div style="max-width:600px;margin:0 auto;padding:24px"><div style="font-weight:700;font-size:15px;margin-bottom:12px">'.$name.'</div>'
            .'<div style="background:#ffffff;border:1px solid #e4eaf0;border-radius:10px;padding:20px;font-size:14px;line-height:1.5">'.$escaped.'</div>'
            .'</div></body></html>';
    }
}
