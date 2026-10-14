<?php

namespace App\Mail;

use RuntimeException;

/**
 * Falha de envio/teste com código sanitizado:
 * not_configured | host_blocked | tls_failure | auth_failed | connection_failed | timeout |
 * recipient_rejected | throttled | provider_error | consent_missing | credential_invalid
 */
class MailException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}
