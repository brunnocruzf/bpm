<?php

namespace App\Jobs;

use App\Engine\ProjectionSync;
use App\Models\ProcessInstance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sincronização adiada da projeção. Necessária porque o Flowable continua o processo
 * de forma assíncrona após a conclusão de um job de external worker. O reconciliador
 * periódico permanece como rede de segurança.
 */
class SyncInstanceProjection implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $instanceId) {}

    public function handle(ProjectionSync $sync): void
    {
        if ($instance = ProcessInstance::find($this->instanceId)) {
            $sync->syncInstance($instance);
        }
    }
}
