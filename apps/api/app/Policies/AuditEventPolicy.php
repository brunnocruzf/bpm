<?php

namespace App\Policies;

use App\Authorization\Permissions;
use App\Models\AuditEvent;
use App\Models\User;

/** Trilha de auditoria: leitura e exportação com permissões próprias; nunca alteração/exclusão. */
class AuditEventPolicy
{
    public function viewAny(User $user): bool
    {
        return Permissions::allows($user, Permissions::AUDIT_READ);
    }

    public function view(User $user, AuditEvent $event): bool
    {
        return Permissions::allows($user, Permissions::AUDIT_READ);
    }

    public function export(User $user): bool
    {
        return Permissions::allows($user, Permissions::AUDIT_EXPORT);
    }

    public function update(User $user, AuditEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, AuditEvent $event): bool
    {
        return false;
    }
}
