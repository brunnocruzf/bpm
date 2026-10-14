<?php

namespace App\Runtime;

use App\Models\FormSubmission;
use App\Models\ProcessCatalog;
use App\Models\ProcessInstance;
use App\Models\TaskProjection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Autorização contextual por definição, instância e tarefa. Nega por padrão.
 * Aplicada em toda consulta e transição (filtros de ownership/candidate groups).
 */
final class Access
{
    public static function canStart(User $user, ProcessCatalog $catalog): bool
    {
        if (! $user->hasRole('initiator') || $catalog->status !== 'active') {
            return false;
        }
        $groups = $catalog->initiator_groups;

        return empty($groups) || array_intersect($groups, $user->groups ?? []) !== [];
    }

    public static function canWork(User $user, TaskProjection $task): bool
    {
        if (! $user->hasRole('task_worker') || ! in_array($task->status, [TaskProjection::OPEN, TaskProjection::CLAIMED], true)) {
            return false;
        }
        if ($task->assignee_id !== null) {
            return $task->assignee_id === $user->id;
        }

        return $user->inGroup($task->candidate_group);
    }

    public static function canClaim(User $user, TaskProjection $task): bool
    {
        return $user->hasRole('task_worker') && $task->status === TaskProjection::OPEN && $task->assignee_id === null && $user->inGroup($task->candidate_group);
    }

    public static function seesAll(User $user): bool
    {
        return $user->hasRole('admin', 'auditor', 'process_admin');
    }

    public static function canViewInstance(User $user, ProcessInstance $instance): bool
    {
        if (self::seesAll($user) || $instance->requester_id === $user->id) {
            return true;
        }

        return self::visibleInstances($user)->whereKey($instance->id)->exists();
    }

    /** @return Builder<ProcessInstance> */
    public static function visibleInstances(User $user): Builder
    {
        $query = ProcessInstance::query();
        if (self::seesAll($user)) {
            return $query;
        }
        $groups = $user->groups ?: ['__none__'];

        return $query->where(function (Builder $q) use ($user, $groups) {
            $q->where('requester_id', $user->id)
                ->orWhereIn('id', TaskProjection::select('process_instance_id')->where(fn ($t) => $t
                    ->whereIn('candidate_group', $groups)
                    ->orWhere('assignee_id', $user->id)
                    ->orWhere('completed_by', $user->id)))
                ->orWhereIn('id', FormSubmission::select('process_instance_id')->where('author_id', $user->id));
        });
    }
}
