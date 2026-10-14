<?php

namespace App\Engine;

use App\Jobs\ProcessEngineCommand;
use App\Models\EngineCommand;

class EngineCommandDispatcher
{
    public function dispatch(EngineCommand $command, int $delaySeconds = 0): void
    {
        $job = ProcessEngineCommand::dispatch($command->id)->onQueue('engine')->afterCommit();
        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }
}
