<?php

namespace App\Forms;

use App\Integrations\IntegrationUnavailable;
use App\Integrations\LookupRunner;
use App\Support\ApiProblem;
use DateTimeImmutable;
use Throwable;

/**
 * Validação servidor-side de submissões Form.io (o frontend nunca é a fonte de verdade).
 *
 * Pipeline:
 *  1. considera apenas chaves do schema (descarta o resto);
 *  2. campos somente leitura recebem o valor do contexto da instância (cópia, ex.: RACF ← atividade 01);
 *  3. consultas AX (axlookup) são reexecutadas e preenchem os campos destino;
 *  4. campos automáticos (usuário, área, data, sequência) são calculados no servidor;
 *  5. visibilidade condicional é avaliada; campos ocultos são descartados e não exigidos;
 *  6. tipos, obrigatoriedade, opções, tamanhos e anexos são validados.
 */
class SubmissionProcessor
{
    private const MAX_ROWS = 200;

    public function __construct(
        private readonly AutoFieldResolver $auto,
        private readonly LookupRunner $lookups,
    ) {}

    /** @param array<string,mixed> $schema @param array<string,mixed> $input */
    public function process(array $schema, array $input, SubmissionContext $ctx): ProcessedSubmission
    {
        $components = (array) ($schema['components'] ?? []);
        $fields = FormioSchema::dataComponents($components);
        $errors = [];
        $meta = ['lookups' => [], 'auto' => []];

        $lookupTargets = [];
        foreach ($fields as $c) {
            if (($c['type'] ?? '') === 'axlookup') {
                foreach ((array) ($c['fillMap'] ?? []) as $m) {
                    $lookupTargets[$m['to']] = true;
                }
            }
        }

        // 1-2. valores brutos e cópias somente leitura
        $data = [];
        foreach ($fields as $c) {
            $key = $c['key'];
            if (isset($c['properties']['tmsaAuto']) || isset($lookupTargets[$key])) {
                $data[$key] = null;
            } elseif (! empty($c['disabled'])) {
                $data[$key] = $ctx->contextData[$key] ?? null;
            } else {
                $data[$key] = $input[$key] ?? null;
            }
        }

        // 3. consultas AX
        foreach ($fields as $c) {
            if (($c['type'] ?? '') !== 'axlookup') {
                continue;
            }
            $key = $c['key'];
            $value = is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
            if ($value === '' || ! ConditionalEvaluator::isVisible($c, $data)) {
                continue;
            }
            $sourceKey = (string) ($c['lookupSource'] ?? '');
            $sources ??= FieldCatalog::lookupSources();
            $source = $sources[$sourceKey] ?? null;
            if (! $source) {
                continue;
            }
            $system = (string) ($source['system'] ?? 'AX');
            try {
                $result = $this->lookups->run($sourceKey, $value, $ctx->instance?->id);
            } catch (IntegrationUnavailable $e) {
                if ($e->reason === 'not_configured') {
                    throw ApiProblem::unavailable("A consulta de \"{$c['label']}\" não está ativa no momento. Avise o administrador das integrações; seus dados não foram perdidos.");
                }
                throw ApiProblem::unavailable("Não foi possível consultar o $system para \"{$c['label']}\". Seus dados não foram perdidos; tente novamente em instantes.");
            }
            if ($result === null) {
                $errors[$key][] = "\"$value\" não foi encontrado no $system.";

                continue;
            }
            foreach ((array) ($c['fillMap'] ?? []) as $m) {
                $data[$m['to']] = $result[$m['from']] ?? null;
            }
            $meta['lookups'][$key] = [
                'source' => $c['lookupSource'],
                'value' => $value,
                'result' => $result,
                'transport' => $this->lookups->transportName($sourceKey),
                'at' => now('UTC')->toIso8601String(),
            ];
        }

        // 4. campos automáticos
        foreach ($fields as $c) {
            if (isset($c['properties']['tmsaAuto'])) {
                $data[$c['key']] = $this->auto->resolve((string) $c['properties']['tmsaAuto'], $c['key'], $ctx);
                $meta['auto'][$c['key']] = $c['properties']['tmsaAuto'];
            }
        }

        // 5-6. duas passadas de visibilidade (campos ocultos podem afetar outras condições)
        $out = [];
        $hidden = [];
        $ignored = [];
        $this->walk($components, $data, null, true, $out, $hidden, $ignored, $ctx, '');
        foreach ($hidden as $key) {
            $data[$key] = null;
        }
        $out = [];
        $hidden = [];
        $this->walk($components, $data, null, true, $out, $hidden, $errors, $ctx, '');

        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return new ProcessedSubmission($out, array_values(array_unique($hidden)), $meta);
    }

    /**
     * Pré-visualização dos valores do servidor para exibição (GET da tarefa): não consome sequência.
     *
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    public function prefill(array $schema, SubmissionContext $ctx): array
    {
        $prefill = [];
        foreach (FormioSchema::dataComponents((array) ($schema['components'] ?? [])) as $c) {
            $key = $c['key'];
            if (isset($c['properties']['tmsaAuto'])) {
                $prefill[$key] = $this->auto->resolve((string) $c['properties']['tmsaAuto'], $key, $ctx);
            } elseif (array_key_exists($key, $ctx->contextData) && $ctx->contextData[$key] !== null) {
                $prefill[$key] = $ctx->contextData[$key];
            }
        }

        return $prefill;
    }

    /**
     * @param  list<array<string,mixed>>  $components
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $row
     * @param  array<string,mixed>  $out
     * @param  list<string>  $hidden
     * @param  array<string,list<string>>  $errors
     */
    private function walk(array $components, array $data, ?array $row, bool $parentVisible, array &$out, array &$hidden, array &$errors, SubmissionContext $ctx, string $prefix): void
    {
        foreach ($components as $c) {
            $type = (string) ($c['type'] ?? '');
            $visible = $parentVisible && ConditionalEvaluator::isVisible($c, $data, $row);

            if (in_array($type, FormioSchema::LAYOUT, true)) {
                $this->walk(FormioSchema::children($c), $data, $row, $visible, $out, $hidden, $errors, $ctx, $prefix);

                continue;
            }
            if (! in_array($type, FormioSchema::INPUTS, true)) {
                continue;
            }

            $key = (string) $c['key'];
            $value = $row !== null ? ($row[$key] ?? null) : ($data[$key] ?? null);
            if (! $visible) {
                if ($row === null) {
                    $hidden[] = $key;
                }

                continue;
            }

            if ($type === 'datagrid') {
                $out[$key] = $this->validateGrid($c, $value, $data, $errors, $ctx, $prefix.$key);

                continue;
            }

            [$normalized, $fieldErrors] = $this->validateValue($c, $value, $ctx);
            foreach ($fieldErrors as $e) {
                $errors[$prefix.$key][] = $e;
            }
            $out[$key] = $normalized;
        }
    }

    /**
     * @param  array<string,list<string>>  $errors
     * @return list<array<string,mixed>>
     */
    private function validateGrid(array $c, mixed $value, array $data, array &$errors, SubmissionContext $ctx, string $path): array
    {
        $rows = is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
        $label = $c['label'] ?? $c['key'];
        $validate = (array) ($c['validate'] ?? []);

        if (count($rows) > self::MAX_ROWS) {
            $errors[$path][] = "\"$label\" aceita no máximo ".self::MAX_ROWS.' linhas.';
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }
        if (! empty($validate['required']) && $rows === []) {
            $errors[$path][] = "Adicione pelo menos uma linha em \"$label\".";
        }
        if (! empty($validate['minLength']) && count($rows) < (int) $validate['minLength']) {
            $errors[$path][] = "\"$label\" exige no mínimo {$validate['minLength']} linha(s).";
        }
        if (! empty($validate['maxLength']) && count($rows) > (int) $validate['maxLength']) {
            $errors[$path][] = "\"$label\" aceita no máximo {$validate['maxLength']} linha(s).";
        }

        $out = [];
        foreach ($rows as $i => $row) {
            $rowOut = [];
            $rowHidden = [];
            $this->walk((array) ($c['components'] ?? []), $data, $row, true, $rowOut, $rowHidden, $errors, $ctx, "{$path}[$i].");
            $out[] = $rowOut;
        }

        return $out;
    }

    /** @return array{0:mixed,1:list<string>} */
    private function validateValue(array $c, mixed $value, SubmissionContext $ctx): array
    {
        $type = (string) $c['type'];
        $label = (string) ($c['label'] ?? $c['key']);
        $validate = (array) ($c['validate'] ?? []);
        $required = ! empty($validate['required']);
        $errors = [];

        if (is_string($value) && in_array($type, ['textfield', 'email', 'phoneNumber', 'axlookup'], true)) {
            $value = trim($value);
        }

        // Valores vazios
        $empty = $value === null || $value === '' || $value === [];
        if ($type === 'checkbox') {
            $bool = is_bool($value) ? $value : ($value === null || $value === '' ? false : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE));
            if ($bool === null) {
                return [false, ["Valor inválido em \"$label\"."]];
            }

            return [$bool, $required && ! $bool ? ["Marque \"$label\"."] : []];
        }
        if ($type === 'selectboxes' && is_array($value) && ! in_array(true, $value, true)) {
            $empty = true;
        }
        if ($empty) {
            $emptyValue = in_array($type, ['file', 'selectboxes'], true) || ($type === 'select' && ! empty($c['multiple'])) ? [] : null;

            return [$emptyValue, $required ? ["Preencha \"$label\"."] : []];
        }

        switch ($type) {
            case 'textfield':
            case 'textarea':
            case 'email':
            case 'phoneNumber':
            case 'axlookup':
                if (is_int($value) || is_float($value)) {
                    $value = (string) $value;
                }
                if (! is_string($value)) {
                    return [null, ["Valor inválido em \"$label\"."]];
                }
                $max = (int) ($validate['maxLength'] ?? 0) ?: ($type === 'textarea' ? 10000 : 1000);
                $max = min($max, 20000);
                if (mb_strlen($value) > $max) {
                    $errors[] = "\"$label\" aceita no máximo $max caracteres.";
                }
                if (! empty($validate['minLength']) && mb_strlen($value) < (int) $validate['minLength']) {
                    $errors[] = "\"$label\" exige no mínimo {$validate['minLength']} caracteres.";
                }
                if (! empty($validate['pattern']) && ! $this->matchesPattern((string) $validate['pattern'], $value)) {
                    $errors[] = "\"$label\" está em formato inválido.";
                }
                if ($type === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "\"$label\" deve ser um e-mail válido.";
                }

                return [$value, $errors];

            case 'number':
            case 'currency':
                if (! is_numeric($value)) {
                    return [null, ["\"$label\" deve ser numérico."]];
                }
                $n = $value + 0;
                if (isset($validate['min']) && $validate['min'] !== '' && $n < (float) $validate['min']) {
                    $errors[] = "\"$label\" deve ser no mínimo {$validate['min']}.";
                }
                if (isset($validate['max']) && $validate['max'] !== '' && $n > (float) $validate['max']) {
                    $errors[] = "\"$label\" deve ser no máximo {$validate['max']}.";
                }

                return [$n, $errors];

            case 'radio':
                $allowed = FormioSchema::optionValues($c);
                if (! is_scalar($value) || ! in_array((string) $value, $allowed, true)) {
                    return [null, ["Opção inválida em \"$label\"."]];
                }

                return [(string) $value, []];

            case 'select':
                $allowed = FormioSchema::optionValues($c);
                if (! empty($c['multiple'])) {
                    $list = is_array($value) ? array_values($value) : [$value];
                    foreach ($list as $v) {
                        if (! is_scalar($v) || ! in_array((string) $v, $allowed, true)) {
                            return [[], ["Opção inválida em \"$label\"."]];
                        }
                    }

                    return [array_map('strval', $list), []];
                }
                if (! is_scalar($value) || ! in_array((string) $value, $allowed, true)) {
                    return [null, ["Opção inválida em \"$label\"."]];
                }

                return [(string) $value, []];

            case 'selectboxes':
                $allowed = FormioSchema::optionValues($c);
                $normalized = [];
                foreach ($allowed as $option) {
                    $normalized[$option] = ! empty($value[$option]);
                }
                if (array_diff(array_keys((array) $value), $allowed) !== []) {
                    $errors[] = "Opção inválida em \"$label\".";
                }

                return [$normalized, $errors];

            case 'datetime':
                if (! is_string($value)) {
                    return [null, ["Data inválida em \"$label\"."]];
                }
                try {
                    new DateTimeImmutable($value);
                } catch (Throwable) {
                    return [null, ["Data inválida em \"$label\"."]];
                }

                return [$value, []];

            case 'file':
                $files = is_array($value) ? array_values($value) : [];
                if (empty($c['multiple']) && count($files) > 1) {
                    $errors[] = "\"$label\" aceita apenas um arquivo.";
                }
                $canonical = [];
                foreach (array_slice($files, 0, 20) as $file) {
                    $id = is_array($file) ? (string) ($file['data']['attachmentId'] ?? '') : '';
                    $resolved = $id !== '' && $ctx->resolveAttachment ? ($ctx->resolveAttachment)($id, (string) $c['key']) : null;
                    if ($resolved === null) {
                        $errors[] = "Anexo inválido ou sem permissão em \"$label\".";

                        continue;
                    }
                    $canonical[] = $resolved;
                }

                return [$canonical, $errors];
        }

        return [null, ["Tipo de campo não suportado em \"$label\"."]];
    }

    private function matchesPattern(string $pattern, string $value): bool
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '10000');
        try {
            return @preg_match('/^(?:'.str_replace('/', '\/', $pattern).')$/u', $value) === 1;
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }
    }
}
