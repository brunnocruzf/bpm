<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditDiff;
use App\Authorization\Permissions;
use App\Engine\EngineCommandProcessor;
use App\Engine\FlowableRestClient;
use App\Integrations\Ax\AxGateway;
use App\Models\BpmGroup;
use App\Models\EngineCommand;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationExecution;
use App\Models\NotificationMessage;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Administração: saúde de integrações (sem segredos), reconciliação de comandos e grupos. */
class AdminController extends ApiController
{
    public function integrations(FlowableRestClient $engine, AxGateway $ax): JsonResponse
    {
        $this->requireRole('admin', 'auditor');

        return response()->json([
            'engine' => ['name' => 'Flowable REST', 'healthy' => $engine->health()],
            // transporte EFETIVO (vínculo REST ativo tem prioridade sobre o configurado)
            'ax' => collect(config('bpm.ax.transports'))->map(fn ($transport, $op) => ['operation' => $op, 'transport' => $ax->transportName((string) $op), 'configured' => $transport])->values(),
            'connections' => IntegrationConnection::withCount(['endpoints', 'endpoints as enabled_endpoints_count' => fn ($q) => $q->where('enabled', true)])->orderBy('name')->get()
                ->map(fn (IntegrationConnection $c) => ['id' => $c->id, 'key' => $c->key, 'name' => $c->name, 'system' => $c->system, 'enabled' => $c->enabled, 'environment' => $c->environment,
                    'endpoints' => $c->endpoints_count, 'enabledEndpoints' => $c->enabled_endpoints_count, 'lastTestOk' => $c->last_test['ok'] ?? null, 'lastTestedAt' => $c->last_tested_at?->toIso8601String(),
                    'openapiImportedAt' => $c->openapi_imported_at?->toIso8601String(), 'changedEndpoints' => IntegrationEndpoint::where('connection_id', $c->id)->whereIn('contract_status', ['changed', 'removed'])->count()]),
            'executions24h' => IntegrationExecution::where('created_at', '>=', now()->subDay())->selectRaw('result, count(*) as total')->groupBy('result')->pluck('total', 'result'),
            'outbox' => EngineCommand::selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state'),
            'notifications' => NotificationMessage::selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state'),
            'recentExecutions' => IntegrationExecution::orderByDesc('created_at')->limit(30)->get(['id', 'system', 'operation', 'transport', 'endpoint_key', 'http_status', 'result', 'attempt', 'duration_ms', 'process_instance_id', 'created_at']),
        ]);
    }

    public function commands(Request $request): JsonResponse
    {
        $this->requireRole('admin', 'auditor', 'process_admin');
        $query = EngineCommand::orderByDesc('created_at');
        if ($state = $request->query('state')) {
            $query->whereIn('state', explode(',', (string) $state));
        }

        return response()->json(['data' => $query->limit(200)->get(['id', 'command', 'state', 'attempts', 'process_instance_id', 'task_projection_id', 'definition_version_id', 'last_error', 'correlation_id', 'created_at', 'confirmed_at', 'available_at'])]);
    }

    public function retryCommand(string $id, EngineCommandProcessor $processor): JsonResponse
    {
        Gate::authorize(Permissions::PROCESSES_MANAGE);
        $command = EngineCommand::find($id) ?? throw ApiProblem::notFound();
        $processor->retry($command);

        return response()->json(['id' => $command->id, 'state' => $command->fresh()->state]);
    }

    public function groups(): JsonResponse
    {
        $this->requireRole('admin', 'designer');
        $counts = [];
        foreach (User::where('is_active', true)->get(['groups']) as $u) {
            foreach ($u->groups ?? [] as $g) {
                $counts[$g] = ($counts[$g] ?? 0) + 1;
            }
        }

        return response()->json(['data' => BpmGroup::orderBy('name')->get()->map(fn ($g) => [
            'key' => $g->key, 'name' => $g->name, 'description' => $g->description,
            'entraGroupId' => $g->entra_group_id, 'email' => $g->email, 'members' => $counts[$g->key] ?? 0,
        ])]);
    }

    public function updateGroup(Request $request, string $key): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $group = BpmGroup::where('key', $key)->first() ?? throw ApiProblem::notFound();
        $before = $group->only(['name', 'description', 'entra_group_id', 'email']);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:400'],
            'entraGroupId' => ['sometimes', 'nullable', 'uuid'],
            'email' => ['sometimes', 'nullable', 'email', 'max:200'],
        ]);
        $group->fill(array_filter([
            'name' => $data['name'] ?? null,
        ], fn ($v) => $v !== null));
        foreach (['description' => 'description', 'entraGroupId' => 'entra_group_id', 'email' => 'email'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $group->{$col} = $data[$in];
            }
        }
        $group->save();
        AuditLogger::record('group.updated', 'bpm_group', $group->id, ['fields' => array_keys($data)], diff: AuditDiff::between($before, $group->only(array_keys($before))), context: ['object_name' => $group->name]);

        return response()->json(['key' => $group->key]);
    }

    public function users(): JsonResponse
    {
        if (! Permissions::allows($this->user(), Permissions::SETTINGS_MANAGE) && ! Permissions::allows($this->user(), Permissions::PROCESSES_MANAGE)) {
            throw ApiProblem::forbidden();
        }

        return response()->json(['data' => User::orderBy('name')->get(['id', 'name', 'area', 'roles', 'groups', 'is_active', 'last_seen_at', 'disabled_reason', 'directory_synced_at', 'user_type'])]);
    }
}
