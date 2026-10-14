<?php

namespace App\Jobs;

use App\Reports\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Gera um relatório fora da requisição interativa (fila "reports"). */
class GenerateReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $jobId) {}

    public function handle(ReportService $service): void
    {
        $service->generate($this->jobId);
    }
}
