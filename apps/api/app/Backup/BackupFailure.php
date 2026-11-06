<?php

namespace App\Backup;

use RuntimeException;

/** Falha do backup ou da restauração com código estável (explicado em IntegrationErrors) e mensagem sem segredos. */
class BackupFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
