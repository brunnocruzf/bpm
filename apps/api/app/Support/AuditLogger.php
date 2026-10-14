<?php

namespace App\Support;

use App\Audit\AuditService;
use App\Models\AuditEvent;
use App\Models\User;

/** Atalho estático para o AuditService (redação, categoria e enriquecimento centralizados). */
final class AuditLogger
{
    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $diff
     * @param  array<string,mixed>  $context
     */
    public static function record(
        string $action,
        string $entityType,
        ?string $entityId,
        array $data = [],
        ?string $processInstanceId = null,
        ?User $actor = null,
        ?array $diff = null,
        string $outcome = 'success',
        array $context = [],
    ): AuditEvent {
        return app(AuditService::class)->record($action, $entityType, $entityId, $data, $processInstanceId, $actor, $diff, $outcome, $context);
    }
}
