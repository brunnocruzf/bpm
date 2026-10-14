<?php

namespace App\Settings;

use App\Models\AppSetting;
use App\Models\User;

/**
 * Tipo de configuração: valores padrão, normalização/validação server-side (Form Request
 * equivalente), cálculo de estado e apresentação segura (sem segredos).
 */
abstract class SettingType
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** @return array<string,mixed> */
    abstract public function defaults(): array;

    /**
     * Valida e normaliza a entrada; armazena segredos via SecretStore e devolve somente referências.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $current
     * @return array<string,mixed>
     */
    abstract public function normalize(array $input, array $current, User $by): array;

    /**
     * Estado calculado após salvar (antes de qualquer teste).
     *
     * @param  array<string,mixed>  $value
     * @return array{0:string,1:?string} [status, detalhe]
     */
    abstract public function statusAfterSave(array $value): array;

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed> valor seguro para a API (referências de segredo substituídas por metadados)
     */
    public function present(array $value): array
    {
        return $value;
    }

    /** Altera o estado efetivo do sistema apenas após validação (ex.: login Entra). */
    public function requiresActivation(): bool
    {
        return false;
    }

    /** @param array<string,mixed> $value */
    public function redactedForHistory(array $value): array
    {
        return $value;
    }

    public function describe(AppSetting $s): array
    {
        // nunca gravada: estado calculado a partir dos valores padrão (ex.: marca padrão = configurada)
        [$status, $detail] = $s->exists ? [$s->status, $s->status_detail] : $this->statusAfterSave($this->defaults());

        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'status' => $status,
            'statusDetail' => $detail,
            'version' => $s->version,
            'value' => $this->present(array_replace_recursive($this->defaults(), $s->value ?? [])),
            'updatedAt' => $s->exists ? $s->updated_at?->toIso8601String() : null,
            'updatedBy' => $s->updated_by_name,
            'lastValidation' => $s->last_validation,
            'lastValidatedAt' => $s->last_validated_at?->toIso8601String(),
            'activatedAt' => $s->activated_at?->toIso8601String(),
            'requiresActivation' => $this->requiresActivation(),
        ];
    }
}
