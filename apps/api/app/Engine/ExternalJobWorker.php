<?php

namespace App\Engine;

use App\Flow\StageCatalog;
use App\Integrations\Ax\AxGateway;
use App\Integrations\IntegrationUnavailable;
use App\Jobs\SyncInstanceProjection;
use App\Models\ProcessInstance;
use App\Models\ProcessStageVersion;
use App\Support\AuditLogger;
use App\Support\Masker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executa service tasks BPMN (Flowable external worker, tópico tmsa-integration).
 * O Flowable não chama o AX: o worker Laravel adquire o job, executa a operação
 * pelo adaptador (idempotente) e conclui/falha o job com retentativa limitada.
 */
class ExternalJobWorker
{
    public function __construct(
        private readonly FlowableRestClient $engine,
        private readonly AxGateway $ax,
    ) {}

    public function run(int $max = 10): int
    {
        $workerId = 'laravel-'.gethostname();
        $jobs = $this->engine->acquireJobs((string) config('bpm.engine.external_worker_topic'), $max, (string) config('bpm.engine.external_worker_lock'), $workerId);

        foreach ($jobs as $job) {
            $instance = ProcessInstance::where('engine_process_instance_id', $job['processInstanceId'] ?? '')->first();
            $stage = $instance ? ProcessStageVersion::where('definition_version_id', $instance->definition_version_id)->where('element_id', $job['elementId'] ?? '')->first() : null;
            $operation = $stage ? StageCatalog::serviceOperations()[(string) $stage->configValue('operation')] ?? null : null;

            if (! $instance || ! $operation) {
                // Consome uma tentativa; após esgotar, o job fica em dead-letter para análise.
                $this->engine->failJob((string) $job['id'], $workerId, 'Instância ou integração não encontrada no BPM TMSA.', max(0, ((int) ($job['retries'] ?? 1)) - 1), 'PT2M');

                continue;
            }

            try {
                $input = array_intersect_key($instance->data ?? [], array_flip($operation['inputs']));
                $key = implode(':', [$instance->id, $stage->element_id, $job['correlationId'] ?? $job['id']]);
                $result = $this->ax->execute($operation['axOperation'], $input, $key, $instance->id);
                $outputs = array_intersect_key($result, array_flip($operation['outputs']));

                DB::transaction(function () use ($instance, $outputs, $stage) {
                    $locked = ProcessInstance::whereKey($instance->id)->lockForUpdate()->first();
                    $locked->update(['data' => array_merge($locked->data ?? [], $outputs)]);
                    AuditLogger::record('integration.completed', 'process_stage_version', $stage->id, ['operation' => $stage->configValue('operation'), 'outputs' => array_keys($outputs)], $instance->id);
                });
                $this->engine->completeJob((string) $job['id'], $workerId, $outputs);
                // A continuação no Flowable é assíncrona: sincroniza depois e libera o reconciliador.
                $instance->forceFill(['synced_at' => null])->saveQuietly();
                SyncInstanceProjection::dispatch($instance->id)->onQueue('engine')->delay(now()->addSeconds(3));
            } catch (IntegrationUnavailable $e) {
                $retries = max(0, ((int) ($job['retries'] ?? 1)) - 1);
                $this->engine->failJob((string) $job['id'], $workerId, Masker::error($e), $retries, 'PT5M');
                AuditLogger::record('integration.failed', 'process_stage_version', $stage->id, ['operation' => $stage->configValue('operation'), 'reason' => $e->reason, 'retriesLeft' => $retries], $instance->id);
            } catch (Throwable $e) {
                Log::error('Falha no worker de integração', ['job' => $job['id'], 'error' => Masker::error($e)]);
            }
        }

        return count($jobs);
    }
}
