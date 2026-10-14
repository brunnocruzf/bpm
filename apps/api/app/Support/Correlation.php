<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Correlation ID propagado entre API, worker, Flowable REST, integrações e notificações. */
final class Correlation
{
    private static ?string $id = null;

    public static function id(): string
    {
        return self::$id ??= (string) Str::uuid();
    }

    public static function set(?string $id): void
    {
        self::$id = ($id !== null && preg_match('/^[A-Za-z0-9\-_.]{8,64}$/', $id)) ? $id : (string) Str::uuid();
    }

    public static function reset(): void
    {
        self::$id = null;
    }
}
