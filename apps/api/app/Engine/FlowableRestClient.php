<?php

namespace App\Engine;

use App\Support\Correlation;
use App\Support\Masker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente service-to-service do Flowable REST (rede interna, credencial técnica).
 * Nunca exposto ao browser; identidade do usuário final não é repassada como credencial.
 */
class FlowableRestClient
{
    /** @return array{deploymentId:string,definitionId:string} */
    public function deploy(string $deploymentName, string $fileName, string $xml): array
    {
        // deploymentName precisa ir na query string (campo multipart é ignorado pelo Flowable 7.2);
        // é a chave usada na reconciliação do deploy.
        $response = $this->send(fn () => $this->http()
            ->withQueryParameters(['deploymentName' => $deploymentName])
            ->attach('file', $xml, $fileName, ['Content-Type' => 'text/xml'])
            ->post('service/repository/deployments'));
        $this->assertOk($response, 'deploy');
        $deploymentId = (string) $response->json('id');

        return ['deploymentId' => $deploymentId, 'definitionId' => $this->definitionForDeployment($deploymentId)];
    }

    /** @return array{deploymentId:string,definitionId:string}|null */
    public function findDeploymentByName(string $deploymentName): ?array
    {
        $response = $this->send(fn () => $this->http()->get('service/repository/deployments', ['name' => $deploymentName]));
        $this->assertOk($response, 'deployments');
        $id = $response->json('data.0.id');

        return $id ? ['deploymentId' => (string) $id, 'definitionId' => $this->definitionForDeployment((string) $id)] : null;
    }

    /** @param array<string,mixed> $variables */
    public function startProcess(string $definitionId, string $businessKey, array $variables): string
    {
        $response = $this->send(fn () => $this->http()->post('service/runtime/process-instances', [
            'processDefinitionId' => $definitionId,
            'businessKey' => $businessKey,
            'variables' => self::toVariables($variables),
            'returnVariables' => false,
        ]));
        $this->assertOk($response, 'start');

        return (string) $response->json('id');
    }

    /** @return array{id:string,ended:bool}|null */
    public function findProcessByBusinessKey(string $businessKey): ?array
    {
        $runtime = $this->send(fn () => $this->http()->get('service/runtime/process-instances', ['businessKey' => $businessKey]));
        $this->assertOk($runtime, 'process-instances');
        if ($id = $runtime->json('data.0.id')) {
            return ['id' => (string) $id, 'ended' => false];
        }
        $history = $this->send(fn () => $this->http()->get('service/history/historic-process-instances', ['businessKey' => $businessKey]));
        $this->assertOk($history, 'historic-process-instances');
        if ($id = $history->json('data.0.id')) {
            return ['id' => (string) $id, 'ended' => $history->json('data.0.endTime') !== null];
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    public function activeTasks(string $processInstanceId): array
    {
        $response = $this->send(fn () => $this->http()->get('service/runtime/tasks', ['processInstanceId' => $processInstanceId, 'size' => 500]));
        $this->assertOk($response, 'tasks');

        return array_values((array) $response->json('data', []));
    }

    /** @return array<string,mixed>|null */
    public function getTask(string $taskId): ?array
    {
        $response = $this->send(fn () => $this->http()->get('service/runtime/tasks/'.rawurlencode($taskId)));
        if ($response->status() === 404) {
            return null;
        }
        $this->assertOk($response, 'task');

        return $response->json();
    }

    /** @return array<string,mixed>|null */
    public function historicTask(string $taskId): ?array
    {
        $response = $this->send(fn () => $this->http()->get('service/history/historic-task-instances/'.rawurlencode($taskId)));
        if ($response->status() === 404) {
            return null;
        }
        $this->assertOk($response, 'historic-task');

        return $response->json();
    }

    public function assignTask(string $taskId, string $assignee): bool
    {
        $response = $this->send(fn () => $this->http()->put('service/runtime/tasks/'.rawurlencode($taskId), ['assignee' => $assignee]));
        if ($response->status() === 404) {
            return false;
        }
        $this->assertOk($response, 'assign');

        return true;
    }

    /** @param array<string,mixed> $variables */
    public function completeTask(string $taskId, array $variables): bool
    {
        $response = $this->send(fn () => $this->http()->post('service/runtime/tasks/'.rawurlencode($taskId), [
            'action' => 'complete',
            'variables' => self::toVariables($variables),
        ]));
        if ($response->status() === 404) {
            return false;
        }
        $this->assertOk($response, 'complete');

        return true;
    }

    /** @return array<string,mixed>|null */
    public function historicProcess(string $processInstanceId): ?array
    {
        $response = $this->send(fn () => $this->http()->get('service/history/historic-process-instances/'.rawurlencode($processInstanceId)));
        if ($response->status() === 404) {
            return null;
        }
        $this->assertOk($response, 'historic-process');

        return $response->json();
    }

    /** @return list<array<string,mixed>> */
    public function acquireJobs(string $topic, int $count, string $lockDuration, string $workerId): array
    {
        $response = $this->send(fn () => $this->http()->post('external-job-api/acquire/jobs', [
            'topic' => $topic, 'lockDuration' => $lockDuration, 'numberOfTasks' => $count, 'workerId' => $workerId,
        ]));
        $this->assertOk($response, 'acquire-jobs');

        return array_values((array) $response->json());
    }

    /** @param array<string,mixed> $variables */
    public function completeJob(string $jobId, string $workerId, array $variables): void
    {
        $response = $this->send(fn () => $this->http()->post('external-job-api/acquire/jobs/'.rawurlencode($jobId).'/complete', [
            'workerId' => $workerId, 'variables' => self::toVariables($variables),
        ]));
        $this->assertOk($response, 'complete-job');
    }

    public function failJob(string $jobId, string $workerId, string $message, int $retries, string $retryTimeout): void
    {
        $response = $this->send(fn () => $this->http()->post('external-job-api/acquire/jobs/'.rawurlencode($jobId).'/fail', [
            'workerId' => $workerId, 'errorMessage' => mb_substr($message, 0, 250), 'retries' => $retries, 'retryTimeout' => $retryTimeout,
        ]));
        $this->assertOk($response, 'fail-job');
    }

    public function health(): bool
    {
        try {
            return $this->http()->timeout(3)->get('actuator/health')->json('status') === 'UP';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Service tasks à espera do worker, sem adquirir nada; vazio quando o engine não responde.
     *
     * @return list<array<string,mixed>>
     */
    public function externalJobs(string $topic): array
    {
        try {
            return array_values((array) $this->http()->get('external-job-api/jobs', ['topic' => $topic, 'size' => 200])->json('data', []));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Versão do engine para o manifesto dos backups; null quando ele não responde. */
    public function version(): ?string
    {
        try {
            return $this->http()->timeout(3)->get('service/management/engine')->json('version');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return list<array<string,mixed>>
     */
    public static function toVariables(array $variables): array
    {
        $out = [];
        foreach ($variables as $name => $value) {
            $out[] = match (true) {
                $value === null => ['name' => (string) $name, 'value' => null],
                is_bool($value) => ['name' => (string) $name, 'type' => 'boolean', 'value' => $value],
                is_int($value) => ['name' => (string) $name, 'type' => abs($value) <= 2147483647 ? 'integer' : 'long', 'value' => $value],
                is_float($value) => ['name' => (string) $name, 'type' => 'double', 'value' => $value],
                default => ['name' => (string) $name, 'type' => 'string', 'value' => (string) $value],
            };
        }

        return $out;
    }

    private function definitionForDeployment(string $deploymentId): string
    {
        $response = $this->send(fn () => $this->http()->get('service/repository/process-definitions', ['deploymentId' => $deploymentId]));
        $this->assertOk($response, 'process-definitions');
        $id = $response->json('data.0.id');
        if (! $id) {
            throw new EngineRejected('Deploy sem definição de processo executável.', 422);
        }

        return (string) $id;
    }

    private function http(): PendingRequest
    {
        $cfg = config('bpm.engine');

        return Http::baseUrl($cfg['base_url'])
            ->withBasicAuth((string) $cfg['username'], (string) $cfg['password'])
            ->acceptJson() // corpo JSON é o padrão; não forçar Content-Type (quebraria o multipart do deploy)
            ->connectTimeout($cfg['connect_timeout'])
            ->timeout($cfg['timeout'])
            ->withHeaders(['X-Correlation-Id' => Correlation::id()]);
    }

    /** @param callable(): Response $request */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new EngineUnavailable('Engine indisponível: '.Masker::error($e));
        }
    }

    private function assertOk(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }
        $detail = Masker::error((string) ($response->json('exception') ?? $response->json('message') ?? substr($response->body(), 0, 300)));
        if ($response->serverError()) {
            throw new EngineUnavailable("Flowable $operation HTTP {$response->status()}: $detail");
        }
        throw new EngineRejected("Flowable $operation HTTP {$response->status()}: $detail", $response->status());
    }
}
