<?php

namespace App\Support;

use RuntimeException;

/**
 * Erro de negócio renderizado como RFC 7807 (application/problem+json).
 * Mensagens em linguagem simples, sem segredos ou PII.
 */
class ApiProblem extends RuntimeException
{
    /**
     * @param  array<string, array<int,string>>  $errors
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly string $problemCode = 'error',
        public readonly array $errors = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($detail ?? $title);
    }

    public static function notFound(string $detail = 'Recurso não encontrado ou sem permissão de acesso.'): self
    {
        return new self(404, 'Não encontrado', $detail, 'not_found');
    }

    public static function forbidden(string $detail = 'Você não tem permissão para esta ação.'): self
    {
        return new self(403, 'Acesso negado', $detail, 'forbidden');
    }

    public static function conflict(string $detail, string $code = 'conflict', array $extra = []): self
    {
        return new self(409, 'Conflito', $detail, $code, [], $extra);
    }

    /** @param array<string, array<int,string>> $errors */
    public static function validation(array $errors, string $detail = 'Verifique os campos destacados.'): self
    {
        return new self(422, 'Dados inválidos', $detail, 'validation_failed', $errors);
    }

    public static function unavailable(string $detail, string $code = 'integration_unavailable'): self
    {
        return new self(503, 'Sistema indisponível', $detail, $code);
    }

    /** @return array<string,mixed> */
    public function toArray(?string $correlationId = null, ?string $instance = null): array
    {
        return array_filter([
            'type' => 'https://bpm.tmsa.local/problems/'.$this->problemCode,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'code' => $this->problemCode,
            'instance' => $instance,
            'correlationId' => $correlationId,
            'errors' => $this->errors ?: null,
            ...$this->extra,
        ], fn ($v) => $v !== null);
    }
}
