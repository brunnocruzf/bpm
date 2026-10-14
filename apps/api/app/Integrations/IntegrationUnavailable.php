<?php

namespace App\Integrations;

use RuntimeException;

/** Sistema externo indisponível, não configurado ou com resposta inválida. */
class IntegrationUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'error')
    {
        parent::__construct($message);
    }
}
