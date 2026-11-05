<?php

namespace App\Forms;

/** Resultado validado de uma submissão: dados finais, campos ocultos e metadados de origem. */
final class ProcessedSubmission
{
    /**
     * @param  array<string,mixed>  $data  somente campos visíveis, já normalizados
     * @param  list<string>  $hiddenKeys  campos do formulário ocultos por condição (limpos no contexto/engine)
     * @param  array<string,mixed>  $metadata  lookups (origem, timestamp) e campos automáticos
     * @param  array<string,mixed>  $references  alvos de consulta sem componente: só o contexto os recebe (nunca o payload nem o engine)
     */
    public function __construct(
        public readonly array $data,
        public readonly array $hiddenKeys,
        public readonly array $metadata,
        public readonly array $references = [],
    ) {}

    /**
     * Patch aplicado ao contexto de negócio da instância.
     *
     * @return array<string,mixed>
     */
    public function contextPatch(): array
    {
        $patch = array_merge($this->data, $this->references);
        foreach ($this->hiddenKeys as $key) {
            $patch[$key] = null;
        }

        return $patch;
    }

    /**
     * Variáveis escalares enviadas ao engine (gateways só usam escalares).
     * Campos ocultos vão como null para não deixar valor obsoleto de um ciclo anterior.
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function engineVariables(): array
    {
        $vars = [];
        foreach ($this->data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $vars[$key] = $value;
            }
        }
        foreach ($this->hiddenKeys as $key) {
            $vars[$key] ??= null;
        }

        return $vars;
    }
}
