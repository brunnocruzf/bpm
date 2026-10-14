<?php

namespace App\Notifications;

use App\Models\BpmGroup;
use App\Models\TaskProjection;
use App\Models\User;

/**
 * Destinatários de uma tarefa = responsáveis efetivos (assignee ou grupo candidato), nunca
 * endereços vindos de variáveis de formulário. Política de grupo:
 *  - members:       membros ativos do grupo com papel TaskWorker e e-mail (deduplicados);
 *  - group_mailbox: caixa do grupo configurada pelo administrador (Administração → Grupos).
 */
class RecipientResolver
{
    /** @return list<array{key:string, userId:?string, name:string, address:string}> */
    public function forTask(TaskProjection $task, string $groupPolicy = 'members', ?string $onlyUserId = null): array
    {
        if ($onlyUserId || $task->assignee_id) {
            $user = User::find($onlyUserId ?? $task->assignee_id);

            return $user && $this->eligible($user) ? [$this->entry($user)] : [];
        }
        if (! $task->candidate_group) {
            return [];
        }
        if ($groupPolicy === 'group_mailbox') {
            $group = BpmGroup::where('key', $task->candidate_group)->first();
            $address = $group?->email;

            return $address && filter_var($address, FILTER_VALIDATE_EMAIL) ? [['key' => 'group:'.$group->key, 'userId' => null, 'name' => $group->name, 'address' => strtolower($address)]] : [];
        }

        $out = [];
        $seen = [];
        $candidates = User::where('is_active', true)->whereNotNull('email')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->inGroup($task->candidate_group) && $u->hasRole('task_worker'));
        foreach ($candidates as $user) {
            if (! $this->eligible($user)) {
                continue;
            }
            $entry = $this->entry($user);
            if (isset($seen[$entry['address']])) {
                continue;
            }
            $seen[$entry['address']] = true;
            $out[] = $entry;
        }

        return $out;
    }

    private function eligible(User $user): bool
    {
        return $user->is_active !== false && $user->email && filter_var($user->email, FILTER_VALIDATE_EMAIL);
    }

    /** @return array{key:string, userId:?string, name:string, address:string} */
    private function entry(User $user): array
    {
        return ['key' => 'user:'.$user->id, 'userId' => $user->id, 'name' => $user->name, 'address' => strtolower((string) $user->email)];
    }
}
