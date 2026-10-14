<?php

namespace App\Identity\Graph;

use RuntimeException;

/**
 * Erro de integração com Entra/Graph com código estável e mensagem legível (sem token/PII).
 * Códigos: identity_unreachable, tenant_invalid, app_not_found, credential_invalid, credential_missing,
 * consent_missing, throttled, timeout, delta_expired, not_found, graph_error, ...
 */
class GraphException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $transient = false,
        public readonly ?int $status = null,
        public readonly ?string $location = null,
    ) {
        parent::__construct($message);
    }
}
