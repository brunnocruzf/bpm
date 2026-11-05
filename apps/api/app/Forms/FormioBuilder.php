<?php

namespace App\Forms;

/**
 * Construtor de schemas Form.io (subset suportado) para seeds versionados e testes.
 * Opções comuns: required, disabled, description, placeholder, when (['campo' => valor, ...]),
 * whenAny, maxLength.
 */
final class FormioBuilder
{
    /** @param list<array<string,mixed>> $components */
    public static function form(array $components): array
    {
        return ['display' => 'form', 'components' => $components];
    }

    public static function text(string $key, string $label, array $o = []): array
    {
        return self::input('textfield', $key, $label, $o);
    }

    public static function textarea(string $key, string $label, array $o = []): array
    {
        return self::input('textarea', $key, $label, $o + ['rows' => 3]);
    }

    public static function number(string $key, string $label, array $o = []): array
    {
        return self::input('number', $key, $label, $o + ['delimiter' => false, 'requireDecimal' => false]);
    }

    public static function date(string $key, string $label, array $o = []): array
    {
        $time = (bool) ($o['time'] ?? false);
        unset($o['time']);
        $format = $time ? 'dd/MM/yyyy HH:mm' : 'dd/MM/yyyy';

        return self::input('datetime', $key, $label, $o + [
            'format' => $format,
            'enableTime' => $time,
            'enableDate' => true,
            'datePicker' => ['disableWeekends' => false, 'disableWeekdays' => false],
            'widget' => [
                'type' => 'calendar', 'displayInTimezone' => 'viewer', 'locale' => 'pt', 'useLocaleSettings' => false,
                'allowInput' => true, 'mode' => 'single', 'enableTime' => $time, 'noCalendar' => false, 'format' => $format,
                'hourIncrement' => 1, 'minuteIncrement' => 1, 'time_24hr' => true, 'minDate' => null, 'maxDate' => null,
            ],
        ]);
    }

    /** Hora (HTML5 input type="time"), gravada como HH:MM em 24 horas. */
    public static function time(string $key, string $label, array $o = []): array
    {
        return self::input('time', $key, $label, $o) + ['inputType' => 'time', 'format' => 'HH:mm', 'dataFormat' => 'HH:mm'];
    }

    /** @param array<string,string> $options value => label */
    public static function radio(string $key, string $label, array $options, array $o = []): array
    {
        return self::input('radio', $key, $label, $o + [
            'inline' => true,
            'values' => array_map(fn ($v, $l) => ['label' => $l, 'value' => (string) $v], array_keys($options), $options),
        ]);
    }

    public static function yesNo(string $key, string $label, array $o = []): array
    {
        return self::radio($key, $label, ['sim' => 'Sim', 'nao' => 'Não'], $o);
    }

    /** @param array<string,string> $options value => label */
    public static function select(string $key, string $label, array $options, array $o = []): array
    {
        return self::input('select', $key, $label, $o + [
            'dataSrc' => 'values',
            'widget' => 'choicesjs',
            'data' => ['values' => array_map(fn ($v, $l) => ['label' => $l, 'value' => (string) $v], array_keys($options), $options)],
        ]);
    }

    public static function checkbox(string $key, string $label, array $o = []): array
    {
        return self::input('checkbox', $key, $label, $o);
    }

    public static function file(string $key, string $label, array $o = []): array
    {
        return self::input('file', $key, $label, $o + ['storage' => 'tmsa', 'multiple' => true, 'webcam' => false, 'fileMaxSize' => '20MB']);
    }

    /** @param array<string,string> $fill campo AX => key destino */
    public static function lookup(string $key, string $label, string $source, array $fill, array $o = []): array
    {
        return self::input('axlookup', $key, $label, $o + [
            'lookupSource' => $source,
            'fillMap' => array_map(fn ($from, $to) => ['from' => $from, 'to' => $to], array_keys($fill), $fill),
        ]);
    }

    /** Campo preenchido pelo servidor (usuário, área, data, sequência...). */
    public static function auto(string $key, string $label, string $source, array $o = []): array
    {
        $type = str_starts_with($source, 'now.') || $source === 'instance.startedAt' ? 'datetime' : (str_starts_with($source, 'counter:') ? 'number' : 'textfield');
        $base = match ($type) {
            'datetime' => self::date($key, $label, $o + ['time' => $source === 'now.datetime']),
            'number' => self::number($key, $label, $o),
            default => self::text($key, $label, $o),
        };
        $base['disabled'] = true;
        $base['properties'] = ['tmsaAuto' => $source];
        // número da solicitação, sequências e comparações só existem depois do envio: a abertura mostra o campo vazio
        $onSubmit = $source === 'instance.number' || str_starts_with($source, 'sequence:') || str_starts_with($source, FieldCatalog::REQUESTER_MATCHES);
        if ($onSubmit) {
            $base['placeholder'] ??= 'Gerado ao enviar';
        }
        $base['description'] ??= $onSubmit ? 'Gerado automaticamente pelo sistema ao enviar.' : 'Preenchido automaticamente pelo sistema.';

        return $base;
    }

    /** Campo copiado de etapa anterior (mesma key; somente leitura). */
    public static function copied(array $component): array
    {
        $component['disabled'] = true;
        $component['validate']['required'] = false;
        unset($component['lookupSource'], $component['fillMap']);
        if ($component['type'] === 'axlookup') {
            $component['type'] = 'textfield';
        }

        return $component;
    }

    /** @param list<array<string,mixed>> $components */
    public static function datagrid(string $key, string $label, array $components, array $o = []): array
    {
        return self::input('datagrid', $key, $label, $o + ['components' => $components, 'addAnother' => 'Adicionar ação', 'reorder' => false]);
    }

    /** @param list<array<string,mixed>> $components */
    public static function panel(string $title, array $components, array $o = []): array
    {
        $panel = ['type' => 'panel', 'key' => $o['key'] ?? 'panel_'.substr(md5($title), 0, 8), 'title' => $title, 'theme' => 'default', 'input' => false, 'components' => $components];
        if (isset($o['when'])) {
            $panel['conditional'] = self::conditional($o['when'], 'all');
        }

        return $panel;
    }

    /** @param list<list<array<string,mixed>>> $cols */
    public static function columns(array $cols): array
    {
        $width = (int) floor(12 / max(1, count($cols)));

        return [
            'type' => 'columns',
            'key' => 'cols_'.substr(md5(json_encode($cols)), 0, 8),
            'input' => false,
            'columns' => array_map(fn ($components) => ['components' => $components, 'width' => $width, 'offset' => 0, 'push' => 0, 'pull' => 0, 'size' => 'md'], $cols),
        ];
    }

    public static function content(string $html, string $key = ''): array
    {
        return ['type' => 'content', 'key' => $key ?: 'content_'.substr(md5($html), 0, 8), 'html' => $html, 'input' => false];
    }

    private static function input(string $type, string $key, string $label, array $o): array
    {
        $c = [
            'type' => $type,
            'key' => $key,
            'label' => $label,
            'input' => true,
            'tableView' => true,
            'validate' => array_filter([
                'required' => (bool) ($o['required'] ?? false),
                'maxLength' => $o['maxLength'] ?? null,
                'minLength' => $o['minLength'] ?? null,
            ], fn ($v) => $v !== null),
        ];
        foreach (['disabled', 'description', 'placeholder', 'tooltip', 'multiple', 'rows', 'inline', 'values', 'data', 'dataSrc', 'widget', 'format', 'enableTime', 'enableDate', 'datePicker', 'storage', 'webcam', 'fileMaxSize', 'lookupSource', 'fillMap', 'components', 'properties', 'addAnother', 'reorder', 'delimiter', 'requireDecimal'] as $prop) {
            if (array_key_exists($prop, $o)) {
                $c[$prop] = $o[$prop];
            }
        }
        if (isset($o['when'])) {
            $c['conditional'] = self::conditional($o['when'], 'all');
        } elseif (isset($o['whenAny'])) {
            $c['conditional'] = self::conditional($o['whenAny'], 'any');
        }

        return $c;
    }

    /** @param array<string,mixed> $rules */
    private static function conditional(array $rules, string $conjunction): array
    {
        return [
            'show' => true,
            'conjunction' => $conjunction,
            'conditions' => array_map(fn ($field, $value) => ['component' => $field, 'operator' => 'isEqual', 'value' => $value], array_keys($rules), $rules),
        ];
    }
}
