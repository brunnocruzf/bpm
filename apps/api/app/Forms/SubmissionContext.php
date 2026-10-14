<?php

namespace App\Forms;

use App\Models\ProcessInstance;
use App\Models\User;
use Closure;

/**
 * Contexto de processamento de uma submissão.
 *
 * $contextData: dados de negócio já registrados na instância (para cópia de campos somente leitura).
 * $resolveAttachment: callback que recebe o ID do anexo e devolve metadados canônicos ou null se não autorizado.
 */
final class SubmissionContext
{
    /**
     * @param  array<string,mixed>  $contextData
     * @param  array<string,mixed>  $instanceInfo  number/requesterName/requesterArea/startedAt
     * @param  (Closure(string, string): (array<string,mixed>|null))|null  $resolveAttachment
     */
    public function __construct(
        public readonly User $user,
        public readonly ?ProcessInstance $instance,
        public readonly array $contextData = [],
        public readonly array $instanceInfo = [],
        public readonly ?Closure $resolveAttachment = null,
        public readonly bool $preview = false,
    ) {}
}
