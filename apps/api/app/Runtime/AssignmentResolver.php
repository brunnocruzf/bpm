<?php

namespace App\Runtime;

use App\Flow\AssignmentRule;
use App\Forms\ProcessedSubmission;
use App\Forms\SubmissionContext;
use App\Models\ProcessDefinitionVersion;
use App\Models\ProcessInstance;
use App\Models\ProcessStageVersion;
use App\Models\User;
use App\Support\ApiProblem;
use Illuminate\Support\Str;

/**
 * Resolve a regra de atribuição da etapa (ADR-0020). A mesma elegibilidade vale nos três momentos:
 *  - envio do formulário (assertSubmission): recusa com 422 quem não pode executar;
 *  - criação da tarefa (forTask): roda no reconciliador, então nunca recusa — cai na contingência;
 *  - reatribuição de tarefa sem grupo (ineligibility).
 */
class AssignmentResolver
{
    /** Motivos de inelegibilidade: código gravado na auditoria => texto mostrado ao usuário. */
    public const REASONS = [
        'no_candidate' => 'nenhum responsável foi encontrado. Verifique os dados informados ou indique outra pessoa.',
        'invalid_reference' => 'o responsável informado é inválido.',
        'user_not_found' => 'o responsável informado não existe no sistema.',
        'user_inactive' => 'o responsável informado está inativo no sistema.',
        'user_without_role' => 'o responsável informado não tem permissão para executar tarefas.',
        'is_requester' => 'o solicitante não pode ser o responsável por esta etapa.',
        'excluded_field' => 'esta pessoa não pode ser a responsável por esta etapa.',
    ];

    /**
     * Decisão para a tarefa que o engine acabou de criar. Sem regra, o grupo é a raia.
     *
     * @return array{mode:string, assigneeId:?string, group:?string, field:?string, reason:?string}
     */
    public function forTask(ProcessStageVersion $stage, ProcessInstance $instance): array
    {
        $rule = AssignmentRule::of($stage);
        if ($rule === null) {
            return ['mode' => AssignmentRule::GROUP, 'assigneeId' => null, 'group' => $stage->lane, 'field' => null, 'reason' => null];
        }
        $data = (array) ($instance->data ?? []);
        [$candidate, $field] = $this->candidate($rule, $data, $instance->requester_id);
        $reason = $this->reason($candidate, $rule, $data, $instance->requester_id);

        return [
            'mode' => $rule['mode'],
            'assigneeId' => $reason === null ? $candidate : null,
            'group' => $reason === null ? null : ($rule['fallbackGroup'] ?? $stage->lane),
            'field' => $field,
            'reason' => $reason,
        ];
    }

    /**
     * Recusa o envio quando o formulário atual alimenta uma etapa em modo field e ela ficaria sem
     * responsável elegível. O erro aponta para a consulta que preencheu a referência.
     */
    public function assertSubmission(ProcessDefinitionVersion $version, ProcessedSubmission $processed, SubmissionContext $ctx): void
    {
        if ($processed->references === []) {
            return;
        }
        $data = array_merge($ctx->contextData, $processed->contextPatch());
        $requesterId = $ctx->instance->requester_id ?? $ctx->user->id;
        $sources = (array) ($processed->metadata['references'] ?? []);
        $errors = [];

        foreach (ProcessStageVersion::where('definition_version_id', $version->id)->where('stage_type', ProcessStageVersion::USER_TASK)->get() as $stage) {
            $rule = AssignmentRule::of($stage);
            if (($rule['mode'] ?? null) !== AssignmentRule::FIELD || ! array_intersect((array) ($rule['fields'] ?? []), array_keys($processed->references)) || $this->exempt($rule, $data)) {
                continue;
            }
            [$candidate, $field] = $this->candidate($rule, $data, $requesterId);
            $reason = $this->reason($candidate, $rule, $data, $requesterId);
            if ($reason !== null) {
                // sem candidato, a referência-base é a última da lista (o primeiro preenchido vence)
                $source = $sources[$field ?? ''] ?? collect($rule['fields'])->reverse()->map(fn ($f) => $sources[$f] ?? null)->filter()->first();
                $errors[$source ?? 'assignment'][] = "\"{$stage->name}\": ".self::REASONS[$reason];
            }
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }
    }

    /** Motivo de $userId não poder executar a etapa desta instância; null quando pode. */
    public function ineligibility(string $userId, ProcessStageVersion $stage, ProcessInstance $instance): ?string
    {
        return $this->reason($userId, AssignmentRule::of($stage) ?? [], (array) ($instance->data ?? []), $instance->requester_id);
    }

    /**
     * Candidato da regra e o campo de onde veio. No modo field o primeiro preenchido vence: um substituto
     * inelegível não devolve a etapa ao gestor imediato.
     *
     * @param  array<string,mixed>  $rule
     * @param  array<string,mixed>  $data
     * @return array{0:mixed, 1:?string}
     */
    private function candidate(array $rule, array $data, string $requesterId): array
    {
        return match ($rule['mode']) {
            AssignmentRule::REQUESTER => [$requesterId, null],
            AssignmentRule::REQUESTER_MANAGER => [User::whereKey($requesterId)->value('manager_user_id'), null],
            default => $this->firstFilled((array) ($rule['fields'] ?? []), $data),
        };
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string,mixed>  $data
     * @return array{0:mixed, 1:?string}
     */
    private function firstFilled(array $fields, array $data): array
    {
        foreach ($fields as $field) {
            if (($data[$field] ?? null) !== null && $data[$field] !== '') {
                return [$data[$field], $field];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<string,mixed>  $rule
     * @param  array<string,mixed>  $data
     */
    private function reason(mixed $candidate, array $rule, array $data, string $requesterId): ?string
    {
        if ($candidate === null || $candidate === '') {
            return 'no_candidate';
        }
        // UUID antes da consulta: no PostgreSQL um valor inválido abortaria a transação do reconciliador
        if (! is_string($candidate) || ! Str::isUuid($candidate)) {
            return 'invalid_reference';
        }
        $user = User::find($candidate);
        if (! $user) {
            return 'user_not_found';
        }
        if ($user->is_active === false || $user->account_enabled === false) {
            return 'user_inactive';
        }
        if (! $user->hasRole('task_worker')) {
            return 'user_without_role';
        }
        if (! empty($rule['excludeRequester']) && $candidate === $requesterId) {
            return 'is_requester';
        }
        foreach ((array) ($rule['excludeFields'] ?? []) as $field) {
            if (($data[$field] ?? null) === $candidate) {
                return 'excluded_field';
            }
        }

        return null;
    }

    /**
     * Caminho em que a etapa não é exigida (ex.: o solicitante já é o gestor e o fluxo vai direto ao RH).
     *
     * @param  array<string,mixed>  $rule
     * @param  array<string,mixed>  $data
     */
    private function exempt(array $rule, array $data): bool
    {
        $conditions = (array) ($rule['requiredUnless'] ?? []);
        foreach ($conditions as $field => $value) {
            if ((string) ($data[$field] ?? '') !== (string) $value) {
                return false;
            }
        }

        return $conditions !== [];
    }
}
