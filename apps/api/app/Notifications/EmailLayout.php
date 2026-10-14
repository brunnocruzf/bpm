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
        ];
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $current @return array<string,string> */
    public static function normalize(array $input, array $current): array
    {
        $value = array_replace(self::defaults(), $current);
        $errors = [];

        if (array_key_exists('accentColor', $input)) {
            $color = strtolower(trim((string) $input['accentColor']));
            if (! Contrast::isHex($color)) {
                $errors['layout.accentColor'][] = 'Use uma cor hexadecimal no formato #rrggbb.';
            } elseif (Contrast::bestForeground($color)[1] < Contrast::TEXT_MIN) {
                $errors['layout.accentColor'][] = 'Escolha uma cor que mantenha contraste legível com texto branco ou escuro.';
            } else {
                $value['accentColor'] = $color;
            }
        }

        foreach (['headerTitle' => 80, 'footerText' => 240] as $field => $max) {
            if (! array_key_exists($field, $input)) {
                continue;
            }
            $text = trim(strip_tags((string) $input[$field]));
            if ($text === '' || mb_strlen($text) > $max) {
                $errors["layout.$field"][] = "Informe um texto com 1 a $max caracteres.";
            } else {
                $value[$field] = $text;
            }
        }

        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $value;
    }
}
