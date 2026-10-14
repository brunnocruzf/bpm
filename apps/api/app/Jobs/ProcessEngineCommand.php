<?php

namespace App\Jobs;

use App\Engine\EngineCommandProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Consome um comando do outbox. Retentativas são controladas pelo estado do outbox. */
class ProcessEngineCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $commandId) {}

    public function handle(EngineCommandProcessor $processor): void
    {
        $processor->process($this->commandId);
    }
}
