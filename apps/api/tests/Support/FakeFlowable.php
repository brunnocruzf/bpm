<?php

namespace Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Emulador mínimo do Flowable REST para testes de contrato do cliente/outbox.
 * Permite simular timeout (resposta perdida) depois de aplicar o comando.
 */
class FakeFlowable
{
    /** @var array<string, array{id:string,name:string}> */
    public array $deployments = [];

    /** @var array<string, array{id:string,businessKey:string,ended:bool}> */
    public array $instances = [];

    /** @var array<string, array<string,mixed>> tarefas ativas */
    public array $tasks = [];

    /** @var array<string, array<string,mixed>> tarefas concluídas */
    public array $history = [];

    public int $startCalls = 0;

    public int $completeCalls = 0;

    /** @var list<string> operações que devem aplicar e depois "perder" a resposta */
    public array $loseResponseOnce = [];

    /** stage keys (taskDefinitionKey) criados após cada evento */
    public array $nextTasksOnStart = [];

    public array $nextTasksOnComplete = [];

    public static function install(): self
    {
        $fake = new self;
        Http::fake(fn (Request $request) => $fake->handle($request));

        return $fake;
    }

    public function handle(Request $request)
    {
        if (! str_contains($request->url(), '/flowable-rest/')) {
            return null; // deixa outros fakes (Graph, Carbone...) responderem
        }
        $path = parse_url($request->url(), PHP_URL_PATH);
        $path = substr($path, strpos($path, '/flowable-rest/') + strlen('/flowable-rest/'));
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $method = $request->method();

        if ($method === 'POST' && $path === 'service/repository/deployments') {
            $id = 'dep-'.Str::random(6);
            $this->deployments[$id] = ['id' => $id, 'name' => $query['deploymentName'] ?? 'x'];

            return $this->maybeLose('deploy', ['id' => $id, 'name' => $query['deploymentName'] ?? 'x']);
        }
        if ($method === 'GET' && $path === 'service/repository/deployments') {
            $found = array_values(array_filter($this->deployments, fn ($d) => $d['name'] === ($query['name'] ?? '')));

            return Http::response(['data' => $found, 'total' => count($found)]);
        }
        if ($method === 'GET' && $path === 'service/repository/process-definitions') {
            return Http::response(['data' => [['id' => 'def-'.($query['deploymentId'] ?? '')]]]);
        }
        if ($method === 'POST' && $path === 'service/runtime/process-instances') {
            $this->startCalls++;
            $id = 'pi-'.Str::random(6);
            $this->instances[$id] = ['id' => $id, 'businessKey' => $request['businessKey'], 'ended' => false];
            foreach ($this->nextTasksOnStart as $key) {
                $this->createTask($id, $key);
            }

            return $this->maybeLose('start', ['id' => $id, 'businessKey' => $request['businessKey'], 'ended' => false]);
        }
        if ($method === 'GET' && $path === 'service/runtime/process-instances') {
            $found = array_values(array_filter($this->instances, fn ($i) => $i['businessKey'] === ($query['businessKey'] ?? '') && ! $i['ended']));

            return Http::response(['data' => $found, 'total' => count($found)]);
        }
        if ($method === 'GET' && $path === 'service/history/historic-process-instances') {
            $found = array_values(array_filter($this->instances, fn ($i) => $i['businessKey'] === ($query['businessKey'] ?? '')));

            return Http::response(['data' => array_map(fn ($i) => $i + ['endTime' => $i['ended'] ? now()->toIso8601String() : null], $found)]);
        }
        if ($method === 'GET' && str_starts_with($path, 'service/history/historic-process-instances/')) {
            $id = substr($path, strlen('service/history/historic-process-instances/'));
            $i = $this->instances[$id] ?? null;

            return $i ? Http::response(['id' => $id, 'endTime' => $i['ended'] ? now()->toIso8601String() : null, 'endActivityId' => $i['ended'] ? 'fim' : null, 'deleteReason' => null]) : Http::response([], 404);
        }
        if ($method === 'GET' && $path === 'service/runtime/tasks') {
            $list = array_values(array_filter($this->tasks, fn ($t) => $t['processInstanceId'] === ($query['processInstanceId'] ?? '')));

            return Http::response(['data' => $list, 'total' => count($list)]);
        }
        if (str_starts_with($path, 'service/runtime/tasks/')) {
            $id = substr($path, strlen('service/runtime/tasks/'));
            if (! isset($this->tasks[$id])) {
                return Http::response(['message' => 'not found'], 404);
            }
            if ($method === 'GET') {
                return Http::response($this->tasks[$id]);
            }
            if ($method === 'PUT') {
                $this->tasks[$id]['assignee'] = $request['assignee'];

                return Http::response($this->tasks[$id]);
            }
            if ($method === 'POST' && $request['action'] === 'complete') {
                $this->completeCalls++;
                $task = $this->tasks[$id];
                unset($this->tasks[$id]);
                $this->history[$id] = $task + ['endTime' => now()->toIso8601String(), 'deleteReason' => null];
                foreach ($this->nextTasksOnComplete as $key) {
                    $this->createTask($task['processInstanceId'], $key);
                }
                $this->nextTasksOnComplete = [];

                return $this->maybeLose('complete', null, 200);
            }
        }
        if ($method === 'GET' && str_starts_with($path, 'service/history/historic-task-instances/')) {
            $id = substr($path, strlen('service/history/historic-task-instances/'));

            return isset($this->history[$id]) ? Http::response($this->history[$id]) : Http::response([], 404);
        }
        if ($path === 'actuator/health') {
            return Http::response(['status' => 'UP']);
        }

        return Http::response(['message' => "Fake não implementa $method $path"], 501);
    }

    public function createTask(string $processInstanceId, string $key): string
    {
        $id = 'task-'.Str::random(6);
        $this->tasks[$id] = ['id' => $id, 'processInstanceId' => $processInstanceId, 'taskDefinitionKey' => $key, 'name' => $key, 'createTime' => now()->toIso8601String(), 'dueDate' => null, 'assignee' => null];

        return $id;
    }

    private function maybeLose(string $operation, ?array $body, int $status = 201)
    {
        if (in_array($operation, $this->loseResponseOnce, true)) {
            $this->loseResponseOnce = array_values(array_diff($this->loseResponseOnce, [$operation]));
            throw new ConnectionException('cURL error 28: Operation timed out (simulado após aplicar)');
        }

        return Http::response($body, $status);
    }
}
