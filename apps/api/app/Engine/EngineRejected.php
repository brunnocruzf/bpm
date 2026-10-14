<?php

namespace App\Engine;

use RuntimeException;

/** O engine recusou o comando de forma definitiva (4xx). Não repetir automaticamente. */
class EngineRejected extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
