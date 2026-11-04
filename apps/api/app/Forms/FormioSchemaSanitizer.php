<?php

namespace App\Forms;

use App\Support\ApiProblem;

/**
 * Normaliza e restringe schemas Form.io produzidos pelo Builder.
 *
 * Regras de segurança do designer (seção 4.2/10 da especificação):
 *  - sem JavaScript livre (customConditional, calculateValue, validate.custom, logic...);
 *  - sem fontes de dados externas em selects (somente lista de valores);
 *  - HTML de conteúdo sanitizado por lista branca; rótulos como texto puro;
 *  - upload sempre via storage TMSA;
 *  - keys estáveis, válidas e únicas.
 */
class FormioSchemaSanitizer
{
    private const FORBIDDEN_KEYS = [
        'customConditional', 'calculateValue', 'calculateServer', 'allowCalculateOverride', 'customDefaultValue',
        'logic', 'custom', 'onChange', 'redrawOn', 'refreshOn', 'dataSrcUrl', 'url', 'fileKey', 'dir', 'uploadOptions',
        'customOptions', 'template', 'selectValues', 'authenticate', 'headers', 'filter', 'searchField', 'valueProperty',
        'lazyLoad', 'encrypted', 'dbIndex', 'persistent_', 'protected', 'action', 'event', 'theme_',
    ];

    private const TEXT_KEYS = ['label', 'description', 'tooltip', 'placeholder', 'legend', 'title', 'prefix', 'suffix', 'errorLabel'];

    private const CONDITION_OPERATORS = [
        'isEqual', 'isNotEqual', 'isEmpty', 'isNotEmpty', 'includes', 'notIncludes',
        'lessThan', 'greaterThan', 'lessThanOrEqual', 'greaterThanOrEqual',
    ];

    /** @var array<string, list<string>> */
    private array $errors = [];

    private int $count = 0;

    /**
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    public function sanitize(array $schema): array
    {
        $this->errors = [];
        $this->count = 0;
        $limits = config('bpm.designer');

        if (strlen((string) json_encode($schema)) > $limits['max_schema_bytes']) {
            throw ApiProblem::validation(['schema' => ['O formulário excede o tamanho máximo permitido.']]);
        }

        $keys = [];
        $components = $this->sanitizeList((array) ($schema['components'] ?? []), 1, $keys, 'components');

        if ($this->count > $limits['max_components']) {
            $this->errors['schema'][] = "O formulário excede {$limits['max_components']} componentes.";
        }
        if ($this->errors) {
            throw ApiProblem::validation($this->errors, 'O formulário contém componentes ou propriedades não suportados.');
        }

        return ['display' => 'form', 'components' => $components];
    }

    /**
     * @param  array<mixed>  $list  vindo do JSON do builder (não necessariamente lista)
     * @param  array<string,bool>  $keys  chaves já usadas no escopo de dados
     * @return list<array<string,mixed>>
     */
    private function sanitizeList(array $list, int $depth, array &$keys, string $path): array
    {
        $out = [];
        foreach (array_values($list) as $i => $component) {
            if (! is_array($component)) {
                continue;
            }
            $clean = $this->sanitizeComponent($component, $depth, $keys, "$path.$i");
            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $c
     * @param  array<string,bool>  $keys
     * @return array<string,mixed>|null
     */
    private function sanitizeComponent(array $c, int $depth, array &$keys, string $path): ?array
    {
        $this->count++;
        $type = (string) ($c['type'] ?? '');
        $key = (string) ($c['key'] ?? '');
        $label = (string) ($c['label'] ?? $key);
        $ref = $key !== '' ? $key : $path;

        if ($type === 'button') {
            return null; // o botão de envio é da aplicação, não do schema
        }
        if (! in_array($type, FormioSchema::allowedTypes(), true)) {
            $this->errors[$ref][] = "Componente \"$label\" do tipo \"$type\" não é suportado nesta versão do produto.";

            return null;
        }
        if ($depth > config('bpm.designer.max_depth')) {
            $this->errors[$ref][] = 'Aninhamento de componentes acima do limite.';

            return null;
        }

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            unset($c[$forbidden]);
        }
        foreach (self::TEXT_KEYS as $textKey) {
            if (isset($c[$textKey])) {
                $c[$textKey] = is_string($c[$textKey]) ? mb_substr(trim(strip_tags($c[$textKey])), 0, 500) : '';
            }
        }

        if (isset($c['validate']) && is_array($c['validate'])) {
            unset($c['validate']['custom'], $c['validate']['customPrivate'], $c['validate']['json'], $c['validate']['customMessage']);
            if (isset($c['validate']['pattern']) && $c['validate']['pattern'] !== '') {
                $pattern = (string) $c['validate']['pattern'];
                if (mb_strlen($pattern) > 200 || @preg_match('/'.str_replace('/', '\/', $pattern).'/u', '') === false) {
                    $this->errors[$ref][] = "Padrão (regex) inválido em \"$label\".";
                }
            }
        }

        if (isset($c['conditional'])) {
            $c['conditional'] = $this->sanitizeConditional($c['conditional'], $ref, $label);
        }

        if (isset($c['properties'])) {
            $c['properties'] = $this->sanitizeProperties((array) $c['properties'], $ref, $label);
        }

        if (in_array($type, FormioSchema::INPUTS, true)) {
            if (! preg_match(FormioSchema::KEY_PATTERN, $key)) {
                $this->errors[$ref][] = "A chave técnica \"$key\" de \"$label\" é inválida (use letras, números e _ iniciando por letra).";
            } elseif (in_array(strtolower($key), FormioSchema::RESERVED_KEYS, true) || str_starts_with(strtolower($key), 'tmsa')) {
                $this->errors[$ref][] = "A chave técnica \"$key\" é reservada pelo sistema.";
            } elseif (isset($keys[$key])) {
                $this->errors[$ref][] = "A chave técnica \"$key\" está repetida no formulário.";
            } else {
                $keys[$key] = true;
            }
            $c['input'] = true;
        }

        switch ($type) {
            case 'select':
                $dataSrc = $c['dataSrc'] ?? 'values';
                if ($dataSrc !== 'values') {
                    $this->errors[$ref][] = "A lista \"$label\" deve usar valores fixos (fontes externas não são permitidas).";
                }
                $c['dataSrc'] = 'values';
                $c['data'] = ['values' => $this->sanitizeOptions((array) ($c['data']['values'] ?? []))];
                $c['widget'] = 'choicesjs';
                break;
            case 'radio':
            case 'selectboxes':
                $c['values'] = $this->sanitizeOptions((array) ($c['values'] ?? []));
                break;
            case 'file':
                $c['storage'] = 'tmsa';
                $c['webcam'] = false;
                $c['fileMaxSize'] = $c['fileMaxSize'] ?? '20MB';
                break;
            case 'time':
                // campo nativo do HTML5; o dado é sempre 24 horas (HH:mm ou HH:mm:ss)
                $c['inputType'] = 'time';
                $c['format'] = 'HH:mm';
                $c['dataFormat'] = in_array($c['dataFormat'] ?? null, ['HH:mm', 'HH:mm:ss'], true) ? $c['dataFormat'] : 'HH:mm';
                unset($c['inputMask']);
                break;
            case 'content':
                $c['html'] = HtmlSanitizer::clean((string) ($c['html'] ?? ''));
                $c['input'] = false;
                break;
            case 'htmlelement':
                $c['content'] = HtmlSanitizer::clean((string) ($c['content'] ?? ''));
                $c['tag'] = in_array($c['tag'] ?? 'p', ['p', 'div', 'span', 'h3', 'h4', 'h5', 'small', 'hr'], true) ? $c['tag'] : 'p';
                $c['attrs'] = [];
                $c['input'] = false;
                break;
            case 'axlookup':
                $source = (string) ($c['lookupSource'] ?? '');
                if (! array_key_exists($source, $this->lookupSources())) {
                    $this->errors[$ref][] = "Selecione a fonte de consulta AX de \"$label\".";
                }
                $c['fillMap'] = $this->sanitizeFillMap((array) ($c['fillMap'] ?? []), $source, $ref);
                break;
        }

        // Layouts e datagrid: sanitiza filhos.
        if ($type === 'columns') {
            $cols = [];
            foreach ((array) ($c['columns'] ?? []) as $col) {
                $col = is_array($col) ? $col : [];
                $cols[] = [
                    'components' => $this->sanitizeList((array) ($col['components'] ?? []), $depth + 1, $keys, "$path.columns"),
                    'width' => (int) ($col['width'] ?? 6),
                    'offset' => 0, 'push' => 0, 'pull' => 0, 'size' => $col['size'] ?? 'md',
                ];
            }
            $c['columns'] = $cols;
        } elseif (in_array($type, FormioSchema::LAYOUT, true)) {
            $c['components'] = $this->sanitizeList((array) ($c['components'] ?? []), $depth + 1, $keys, "$path.components");
        } elseif ($type === 'datagrid') {
            $rowKeys = [];
            $c['components'] = $this->sanitizeList((array) ($c['components'] ?? []), $depth + 1, $rowKeys, "$path.components");
            foreach (FormioSchema::dataComponents($c['components']) as $child) {
                if (($child['type'] ?? '') === 'datagrid') {
                    $this->errors[$ref][] = 'Repetidor dentro de repetidor não é suportado.';
                }
            }
        }

        return $c;
    }

    /** @return array<string,mixed> */
    private function sanitizeConditional(mixed $cond, string $ref, string $label): array
    {
        if (! is_array($cond)) {
            return [];
        }
        $show = $cond['show'] ?? null;
        $show = $show === null || $show === '' ? null : filter_var($show, FILTER_VALIDATE_BOOLEAN);

        if (! empty($cond['conditions']) && is_array($cond['conditions'])) {
            $conditions = [];
            foreach ($cond['conditions'] as $rule) {
                $op = (string) ($rule['operator'] ?? '');
                $component = (string) ($rule['component'] ?? '');
                if (! in_array($op, self::CONDITION_OPERATORS, true)) {
                    $this->errors[$ref][] = "Operador de condição \"$op\" não suportado em \"$label\".";

                    continue;
                }
                if (! preg_match('/^[A-Za-z][A-Za-z0-9_.]{0,120}$/', $component)) {
                    $this->errors[$ref][] = "Condição de \"$label\" referencia um campo inválido.";

                    continue;
                }
                $value = $rule['value'] ?? null;
                $conditions[] = ['component' => $component, 'operator' => $op, 'value' => is_scalar($value) || $value === null ? $value : null];
            }

            return [
                'show' => $show ?? true,
                'conjunction' => ($cond['conjunction'] ?? 'all') === 'any' ? 'any' : 'all',
                'conditions' => array_slice($conditions, 0, 10),
            ];
        }

        if (! empty($cond['when'])) {
            return ['show' => $show ?? true, 'when' => (string) $cond['when'], 'eq' => is_scalar($cond['eq'] ?? null) ? (string) $cond['eq'] : ''];
        }

        return ['show' => null, 'when' => null, 'eq' => ''];
    }

    /** @return array<string,string> */
    private function sanitizeProperties(array $props, string $ref, string $label): array
    {
        $out = [];
        foreach ($props as $k => $v) {
            if (! is_string($k) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,40}$/', $k) || ! is_scalar($v)) {
                continue;
            }
            $v = mb_substr(strip_tags((string) $v), 0, 200);
            if ($k === 'tmsaAuto' && ! FieldCatalog::isValidAuto($v)) {
                $this->errors[$ref][] = "Preenchimento automático \"$v\" de \"$label\" não é suportado.";

                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /** @return list<array{label:string,value:string}> */
    private function sanitizeOptions(array $options): array
    {
        $out = [];
        foreach (array_slice($options, 0, 200) as $o) {
            if (! is_array($o)) {
                continue;
            }
            $value = mb_substr(strip_tags((string) ($o['value'] ?? '')), 0, 120);
            if ($value === '') {
                continue;
            }
            $out[] = ['label' => mb_substr(strip_tags((string) ($o['label'] ?? $value)), 0, 200), 'value' => $value];
        }

        return $out;
    }

    /** @var array<string, array<string,mixed>>|null fontes de consulta (fixas + publicadas no catálogo de integrações) */
    private ?array $lookupMemo = null;

    /** @return array<string, array<string,mixed>> */
    private function lookupSources(): array
    {
        return $this->lookupMemo ??= FieldCatalog::lookupSources();
    }

    /** @return list<array{from:string,to:string}> */
    private function sanitizeFillMap(array $map, string $source, string $ref): array
    {
        $fields = $this->lookupSources()[$source]['fields'] ?? [];
        $out = [];
        foreach (array_slice($map, 0, 10) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $from = (string) ($row['from'] ?? '');
            $to = (string) ($row['to'] ?? '');
            if ($from === '' && $to === '') {
                continue;
            }
            if (! array_key_exists($from, $fields) || ! preg_match(FormioSchema::KEY_PATTERN, $to)) {
                $this->errors[$ref][] = "Mapeamento de consulta AX inválido ($from → $to).";

                continue;
            }
            $out[] = ['from' => $from, 'to' => $to];
        }

        return $out;
    }
}
