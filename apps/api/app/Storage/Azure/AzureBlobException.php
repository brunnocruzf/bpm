<?php

namespace App\Storage\Azure;

use RuntimeException;

/**
 * Falha do Azure Blob Storage com código estável (explicado em IntegrationErrors). A mensagem é sempre
 * genérica: nunca carrega corpo, cabeçalhos, URL com assinatura ou chave.
 */
class AzureBlobException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly ?int $status = null, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}
