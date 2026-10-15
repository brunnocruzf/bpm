<?php

namespace App\Notifications;

use App\Branding\Contrast;
use App\Support\ApiProblem;

/** Configuração segura do template HTML compartilhado por todos os e-mails do BPM. */
final class EmailLayout
{
    public static function defaults(): array
    {
        return [
            'accentColor' => '#f36d32',
            'headerTitle' => 'BPM TMSA',
            'footerText' => 'Mensagem automática do BPM TMSA. Não responda a este e-mail.',
            'linkHelpText' => 'Se o botão não abrir, copie este endereço no navegador:',
            'texts' => self::defaultTexts(),
        ];
    }

    /** @return array<string,array{eyebrow:string,headline:string,actionLabel:string,bodyIntro:string}> */
    public static function defaultTexts(): array
    {
        return [
            'task.assigned' => ['eyebrow' => 'Ação necessária', 'headline' => 'Uma tarefa aguarda sua atenção', 'actionLabel' => 'Acessar tarefa', 'bodyIntro' => ''],
            'stage.completed' => ['eyebrow' => 'Atualização do processo', 'headline' => 'Etapa concluída', 'actionLabel' => 'Acompanhar solicitação', 'bodyIntro' => ''],
            'instance.ended' => ['eyebrow' => 'Processo finalizado', 'headline' => 'Solicitação encerrada', 'actionLabel' => 'Ver solicitação', 'bodyIntro' => ''],
            'identity.invitation' => ['eyebrow' => 'Acesso ao BPM', 'headline' => 'Você recebeu um convite', 'actionLabel' => 'Ativar minha conta', 'bodyIntro' => ''],
            'identity.password_reset' => ['eyebrow' => 'Segurança da conta', 'headline' => 'Redefina sua senha', 'actionLabel' => 'Criar nova senha', 'bodyIntro' => ''],
            'admin.alert' => ['eyebrow' => 'Operação do sistema', 'headline' => 'Alerta do BPM', 'actionLabel' => 'Abrir o BPM', 'bodyIntro' => ''],
            'email.test' => ['eyebrow' => 'Verificação de entrega', 'headline' => 'E-mail de teste', 'actionLabel' => 'Acessar o BPM', 'bodyIntro' => ''],
        ];
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $current @return array<string,string> */
    public static function normalize(array $input, array $current): array
    {
        $value = array_replace(self::defaults(), $current);
        $errors = [];

        if (array_key_exists('accentColor', $input)) {
            $color = is_string($input['accentColor']) ? strtolower(trim($input['accentColor'])) : '';
            if (! Contrast::isHex($color)) {
                $errors['layout.accentColor'][] = 'Use uma cor hexadecimal no formato #rrggbb.';
            } elseif (Contrast::bestForeground($color)[1] < Contrast::TEXT_MIN) {
                $errors['layout.accentColor'][] = 'Escolha uma cor que mantenha contraste legível com texto branco ou escuro.';
            } else {
                $value['accentColor'] = $color;
            }
        }

        foreach (['headerTitle' => 80, 'footerText' => 240, 'linkHelpText' => 160] as $field => $max) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            if (! is_string($input[$field])) {
                $errors["layout.$field"][] = 'Informe um texto válido.';

                continue;
            }
            $text = trim(strip_tags($input[$field]));
            if ($text === '' || mb_strlen($text) > $max) {
                $errors["layout.$field"][] = "Informe um texto com 1 a $max caracteres.";
            } else {
                $value[$field] = $text;
            }
        }

        $value['texts'] = array_replace_recursive(self::defaultTexts(), (array) ($value['texts'] ?? []));
        if (array_key_exists('texts', $input) && ! is_array($input['texts'])) {
            $errors['layout.texts'][] = 'Informe os textos por tipo de e-mail.';
        }
        if (isset($input['texts']) && is_array($input['texts'])) {
            $allowedFields = ['eyebrow' => 80, 'headline' => 140, 'actionLabel' => 80, 'bodyIntro' => 500];
            foreach ($input['texts'] as $event => $fields) {
                if (! array_key_exists((string) $event, self::defaultTexts()) || ! is_array($fields)) {
                    $errors["layout.texts.$event"][] = 'Tipo de e-mail inválido.';

                    continue;
                }
                foreach ($fields as $field => $raw) {
                    if (! array_key_exists((string) $field, $allowedFields)) {
                        $errors["layout.texts.$event.$field"][] = 'Campo de texto não permitido.';

                        continue;
                    }
                    if (! is_string($raw)) {
                        $errors["layout.texts.$event.$field"][] = 'Informe um texto válido.';

                        continue;
                    }
                    $text = trim(strip_tags($raw));
                    $max = $allowedFields[$field];
                    if (($field !== 'bodyIntro' && $text === '') || mb_strlen($text) > $max) {
                        $errors["layout.texts.$event.$field"][] = $field === 'bodyIntro'
                            ? "Use no máximo $max caracteres."
                            : "Informe um texto com 1 a $max caracteres.";
                    } else {
                        $value['texts'][$event][$field] = $text;
                    }
                }
            }
        }

        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $value;
    }
}
