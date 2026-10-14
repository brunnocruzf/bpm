<?php

namespace App\Jobs;

use App\Identity\DirectorySyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Executa uma sincronização/prévia do diretório no worker (nunca no request HTTP nem no browser). */
class RunDirectorySync implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3500;

    public function __construct(public readonly string $runId) {}

    public function handle(DirectorySyncService $service): void
    {
        $service->execute($this->runId);
    }
}
