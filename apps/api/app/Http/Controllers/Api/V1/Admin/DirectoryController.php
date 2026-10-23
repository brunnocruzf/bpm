<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditDiff;
use App\Authorization\Permissions;
use App\Http\Controllers\Api\V1\ApiController;
use App\Identity\DirectorySyncService;
use App\Models\BpmGroup;
use App\Models\DirectoryGroup;
use App\Models\DirectoryRoleMapping;
use App\Models\DirectorySyncRun;
use App\Settings\Types\DirectorySyncSettings;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use App\Support\IntegrationErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sincronização do diretório (prévia, execução, histórico) e mapeamentos grupo Entra → TMSA.
 * A execução ocorre no worker (fila "directory"); o browser só solicita e acompanha.
 */
class DirectoryController extends ApiController
{
    public function __construct(private readonly DirectorySyncService $sync) {}

    private function authorizeSettings(): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
    }

    public function preview(): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json($this->present($this->sync->request('preview', 'manual', $this->user())), 202);
    }

    public function run(Request $request): JsonResponse
    {
        $this->authorizeSettings();
        $data = $request->validate(['mode' => ['sometimes', 'in:delta,full']]);
        $cfg = $this->sync->config();
        if (! $cfg['enabled']) {
            throw ApiProblem::conflict('Habilite a sincronização antes de executar (a prévia funciona com ela desabilitada).', 'sync_disabled');
        }

        return response()->json($this->present($this->sync->request($data['mode'] ?? 'delta', 'manual', $this->user())), 202);
    }

    public function runs(Request $request): JsonResponse
    {
        $this->authorizeSettings();
        $runs = DirectorySyncRun::orderByDesc('created_at')->limit(min(100, max(1, (int) $request->query('limit', 30))))->get();

        return response()->json(['data' => $runs->map(fn ($r) => $this->present($r, false)), 'overview' => $this->sync->overview()]);
    }

    public function showRun(string $id): JsonResponse
    {
        $this->authorizeSettings();
        $run = DirectorySyncRun::find($id) ?? throw ApiProblem::notFound();

        return response()->json($this->present($run, true));
    }

    public function groups(): JsonResponse
    {
        $this->authorizeSettings();
        $cfg = $this->sync->config();
        $selected = array_column($cfg['groups'], 'name', 'id');
        $all = DirectorySyncSettings::allDirectory($cfg);
        $imported = DirectoryGroup::withCount(['memberships' => fn ($q) => $q->whereNull('removed_at')])->orderBy('display_name')->get();

        return response()->json(['data' => $imported->map(fn (DirectoryGroup $g) => [
            'objectId' => $g->object_id, 'name' => $g->display_name, 'mail' => $g->mail, 'members' => $g->memberships_count,
            'removed' => $g->removed_at !== null, 'selected' => $all || array_key_exists($g->object_id, $selected), 'syncedAt' => $g->synced_at?->toIso8601String(),
        ])]);
    }

    public function mappings(): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json([
            'data' => DirectoryRoleMapping::orderBy('entra_group_name')->get()->map(fn ($m) => $this->presentMapping($m)),
            'roles' => array_values((array) config('bpm.roles')),
            'privilegedRoles' => array_values((array) config('bpm.privileged_roles')),
            'groups' => BpmGroup::orderBy('name')->get(['key', 'name']),
        ]);
    }

    public function storeMapping(Request $request): JsonResponse
    {
        $this->authorizeSettings();
        $data = $this->validateMapping($request);
        $mapping = DB::transaction(function () use ($data) {
            $m = DirectoryRoleMapping::create([...$data, 'created_by' => $this->user()->id, 'created_by_name' => $this->user()->name]);
            AuditLogger::record('directory.mapping_created', 'directory_role_mapping', $m->id, ['entraGroup' => $m->entra_group_id, 'tmsaGroup' => $m->tmsa_group_key, 'roles' => $m->tmsa_roles, 'privileged' => $m->privileged, 'justification' => $m->justification], null, $this->user(), context: ['object_name' => $m->entra_group_name]);
            $this->sync->recomputeRoles();

            return $m;
        });

        return response()->json($this->presentMapping($mapping), 201);
    }

    public function updateMapping(Request $request, string $id): JsonResponse
    {
        $this->authorizeSettings();
        $mapping = DirectoryRoleMapping::find($id) ?? throw ApiProblem::notFound();
        $data = $this->validateMapping($request, $mapping);
        DB::transaction(function () use ($mapping, $data) {
            $before = $mapping->only(['tmsa_group_key', 'tmsa_roles', 'privileged', 'enabled']);
            $mapping->update($data);
            AuditLogger::record('directory.mapping_updated', 'directory_role_mapping', $mapping->id, ['justification' => $mapping->justification], null, $this->user(),
                diff: AuditDiff::between($before, $mapping->only(['tmsa_group_key', 'tmsa_roles', 'privileged', 'enabled'])), context: ['object_name' => $mapping->entra_group_name]);
            $this->sync->recomputeRoles();
        });

        return response()->json($this->presentMapping($mapping->fresh()));
    }

    public function deleteMapping(string $id): JsonResponse
    {
        $this->authorizeSettings();
        $mapping = DirectoryRoleMapping::find($id) ?? throw ApiProblem::notFound();
        DB::transaction(function () use ($mapping) {
            AuditLogger::record('directory.mapping_deleted', 'directory_role_mapping', $mapping->id, ['entraGroup' => $mapping->entra_group_id, 'roles' => $mapping->tmsa_roles, 'tmsaGroup' => $mapping->tmsa_group_key], null, $this->user(), context: ['object_name' => $mapping->entra_group_name]);
            $mapping->delete();
            $this->sync->recomputeRoles();
        });

        return response()->json(null, 204);
    }

    /** @return array<string,mixed> */
    private function validateMapping(Request $request, ?DirectoryRoleMapping $current = null): array
    {
        $roles = array_values((array) config('bpm.roles'));
        $data = $request->validate([
            'entraGroupId' => [$current ? 'sometimes' : 'required', 'uuid'],
            'entraGroupName' => ['sometimes', 'nullable', 'string', 'max:256'],
            'tmsaGroupKey' => ['sometimes', 'nullable', 'string', 'exists:bpm_groups,key'],
            'tmsaRoles' => ['sometimes', 'array', 'max:10'],
            'tmsaRoles.*' => ['string', 'in:'.implode(',', $roles)],
            'privileged' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
            'justification' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $out = array_filter([
            'entra_group_id' => isset($data['entraGroupId']) ? strtolower($data['entraGroupId']) : null,
            'entra_group_name' => isset($data['entraGroupName']) ? strip_tags((string) $data['entraGroupName']) : null,
        ], fn ($v) => $v !== null);
        foreach (['tmsaGroupKey' => 'tmsa_group_key', 'tmsaRoles' => 'tmsa_roles', 'privileged' => 'privileged', 'enabled' => 'enabled'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $out[$col] = $data[$in];
            }
        }
        if (array_key_exists('justification', $data)) {
            $out['justification'] = $data['justification'] !== null ? strip_tags((string) $data['justification']) : null;
        }
        $rolesAfter = $out['tmsa_roles'] ?? $current->tmsa_roles ?? [];
        $groupAfter = array_key_exists('tmsa_group_key', $out) ? $out['tmsa_group_key'] : $current?->tmsa_group_key;
        if (! $rolesAfter && ! $groupAfter) {
            throw ApiProblem::validation(['tmsaRoles' => ['Informe ao menos um papel ou um grupo/raia.']]);
        }
        $privileged = array_values(array_intersect($rolesAfter, (array) config('bpm.privileged_roles')));
        $flag = $out['privileged'] ?? $current->privileged ?? false;
        $justification = $out['justification'] ?? $current?->justification;
        if ($privileged && (! $flag || mb_strlen((string) $justification) < 10)) {
            throw ApiProblem::validation(['privileged' => ['Papéis privilegiados ('.implode(', ', $privileged).') exigem marcar "privilegiado" e justificar (mín. 10 caracteres).']]);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function presentMapping(DirectoryRoleMapping $m): array
    {
        return [
            'id' => $m->id, 'entraGroupId' => $m->entra_group_id, 'entraGroupName' => $m->entra_group_name, 'tmsaGroupKey' => $m->tmsa_group_key,
            'tmsaRoles' => $m->tmsa_roles, 'privileged' => $m->privileged, 'enabled' => $m->enabled, 'justification' => $m->justification,
            'createdBy' => $m->created_by_name, 'updatedAt' => $m->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    private function present(DirectorySyncRun $r, bool $detail = true): array
    {
        return [
            'id' => $r->id, 'mode' => $r->mode, 'trigger' => $r->trigger, 'status' => $r->status, 'transport' => $r->transport,
            'startedAt' => $r->started_at?->toIso8601String(), 'finishedAt' => $r->finished_at?->toIso8601String(), 'durationMs' => $r->duration_ms,
            'totals' => $r->totals, 'errorCode' => $r->error_code, 'errorMessage' => $r->error_message, 'cursorAdvanced' => $r->cursor_advanced,
            'requestedBy' => $r->requested_by_name, 'createdAt' => $r->created_at?->toIso8601String(), 'correlationId' => $r->correlation_id,
            'errorsCount' => count($r->errors ?? []),
            ...($detail ? ['errors' => $r->errors, 'plan' => $r->plan, 'log' => $r->log ?? [], 'explain' => IntegrationErrors::explain($r->error_code, 'directory')] : []),
        ];
    }
}
