<?php

namespace App\Backup;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Horários de um agendamento, calculados no fuso configurado e devolvidos em UTC. */
final class BackupSchedule
{
    /**
     * Último horário previsto até agora, ou null se o agendamento é mais novo que ele.
     *
     * @param  array<string,mixed>  $schedule
     */
    public static function lastDue(array $schedule, string $timezone, CarbonInterface $now): ?CarbonImmutable
    {
        $base = CarbonImmutable::instance($now)->setTimezone($timezone);
        $slot = self::slot($schedule, $base);
        if ($slot->greaterThan($base)) {
            $slot = self::slot($schedule, self::previous($schedule, $base));
        }
        $created = isset($schedule['createdAt']) ? CarbonImmutable::parse((string) $schedule['createdAt']) : null;

        return $created && $slot->lessThan($created) ? null : $slot->utc();
    }

    /** @param array<string,mixed> $schedule */
    public static function next(array $schedule, string $timezone, CarbonInterface $now): CarbonImmutable
    {
        $base = CarbonImmutable::instance($now)->setTimezone($timezone);
        $slot = self::slot($schedule, $base);
        if ($slot->lessThanOrEqualTo($base)) {
            $slot = self::slot($schedule, self::following($schedule, $base));
        }

        return $slot->utc();
    }

    /** @param array<string,mixed> $schedule Atraso a partir do qual o agendamento está em falta: uma vez e meia o período. */
    public static function overdueAfterHours(array $schedule): int
    {
        return match ($schedule['frequency']) {
            'daily' => 36,
            'weekly' => 252,
            default => 1116,
        };
    }

    /** @param array<string,mixed> $schedule */
    private static function slot(array $schedule, CarbonImmutable $base): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) $schedule['time']));
        $day = match ($schedule['frequency']) {
            'weekly' => $base->startOfWeek(CarbonInterface::SUNDAY)->addDays((int) $schedule['weekday']),
            'monthly' => $base->startOfMonth()->addDays(($schedule['monthDay'] === 'last' ? $base->daysInMonth : (int) $schedule['monthDay']) - 1),
            default => $base,
        };

        return $day->setTime($hour, $minute);
    }

    /** @param array<string,mixed> $schedule */
    private static function previous(array $schedule, CarbonImmutable $base): CarbonImmutable
    {
        return match ($schedule['frequency']) {
            'weekly' => $base->subWeek(),
            'monthly' => $base->startOfMonth()->subMonth(),
            default => $base->subDay(),
        };
    }

    /** @param array<string,mixed> $schedule */
    private static function following(array $schedule, CarbonImmutable $base): CarbonImmutable
    {
        return match ($schedule['frequency']) {
            'weekly' => $base->addWeek(),
            'monthly' => $base->startOfMonth()->addMonth(),
            default => $base->addDay(),
        };
    }
}
