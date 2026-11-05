<?php

namespace App\Forms;

use App\Runtime\SequenceService;
use Carbon\CarbonImmutable;

/** Resolve campos automáticos (properties.tmsaAuto). Valores sempre calculados no servidor. */
class AutoFieldResolver
{
    public function __construct(private readonly SequenceService $sequences) {}

    /** @param array<string,mixed>|null $data valores já calculados da submissão (null na pré-visualização) */
    public function resolve(string $source, string $fieldKey, SubmissionContext $ctx, ?array $data = null): mixed
    {
        $tz = config('bpm.sequences.timezone');
        $now = CarbonImmutable::now($tz);

        if (str_starts_with($source, 'sequence:')) {
            $existing = $ctx->contextData[$fieldKey] ?? null;
            if (is_string($existing) && $existing !== '') {
                return $existing; // número estável entre revisões (ex.: RACF reeditado)
            }
            if ($ctx->preview) {
                return null;
            }
            $key = substr($source, 9);
            $seq = $this->sequences->next('field:'.$key, $now);

            return $this->sequences->format($key, $seq['value'], $seq['year']);
        }

        if (str_starts_with($source, 'counter:')) {
            return (int) ($ctx->contextData[$fieldKey] ?? 0) + 1;
        }

        if (str_starts_with($source, FieldCatalog::REQUESTER_MATCHES)) {
            if ($data === null) {
                return null;
            }
            $reference = substr($source, strlen(FieldCatalog::REQUESTER_MATCHES));
            $value = $data[$reference] ?? $ctx->contextData[$reference] ?? null;

            return $value !== null && $value === $ctx->instance?->requester_id ? 'sim' : 'nao';
        }

        return match ($source) {
            'user.name' => $ctx->user->name,
            'user.area' => $ctx->user->area,
            'user.email' => $ctx->user->email,
            'user.registration' => $ctx->user->registration_number,
            'now.date' => $now->startOfDay()->toIso8601String(),
            'now.datetime' => $now->toIso8601String(),
            'instance.number' => $ctx->instanceInfo['number'] ?? null,
            'instance.requesterName' => $ctx->instanceInfo['requesterName'] ?? $ctx->user->name,
            'instance.requesterArea' => $ctx->instanceInfo['requesterArea'] ?? $ctx->user->area,
            'instance.startedAt' => $ctx->instanceInfo['startedAt'] ?? $now->toIso8601String(),
            default => null,
        };
    }
}
