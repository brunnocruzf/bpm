<?php

namespace App\Audit;

/**
 * Contexto do ator quando não há usuário autenticado (workers, scheduler, handlers de eventos do
 * engine). O ator de sistema é identificado como tal — nunca se finge um usuário humano.
 */
final class AuditContext
{
    private static ?string $systemActor = null;

    private static ?string $causationId = null;

    public static function system(string $actor): void
    {
        self::$systemActor = $actor;
    }

    public static function systemActor(): ?string
    {
        return self::$systemActor;
    }

    /** Evento/registro que causou as próximas gravações (ex.: comando do outbox). */
    public static function causedBy(?string $id): void
    {
        self::$causationId = $id;
    }

    public static function causationId(): ?string
    {
        return self::$causationId;
    }

    public static function reset(): void
    {
        self::$systemActor = null;
        self::$causationId = null;
    }

    /**
     * Executa $fn como ator de sistema, restaurando o contexto anterior.
     *
     * @template T
     *
     * @param  callable():T  $fn
     * @return T
     */
    public static function as(string $actor, callable $fn, ?string $causationId = null): mixed
    {
        [$prevActor, $prevCause] = [self::$systemActor, self::$causationId];
        self::$systemActor = $actor;
        self::$causationId = $causationId ?? $prevCause;
        try {
            return $fn();
        } finally {
            self::$systemActor = $prevActor;
            self::$causationId = $prevCause;
        }
    }
}
