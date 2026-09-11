<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class ServiceHoursEvaluator
{
    /**
     * @param  array<int, array{openMinutes: int, closeMinutes: int}|null>  $scheduleByDay
     */
    public static function isAvailable(CarbonImmutable $instant, string $timezone, array $scheduleByDay): bool
    {
        $local = $instant->timezone($timezone);
        $dayOfWeek = $local->dayOfWeek;
        $schedule = $scheduleByDay[$dayOfWeek] ?? null;

        if ($schedule === null || ! isset($schedule['openMinutes'], $schedule['closeMinutes'])) {
            return false;
        }

        $nowMinutes = ($local->hour * 60) + $local->minute;
        $open = (int) $schedule['openMinutes'];
        $close = (int) $schedule['closeMinutes'];

        return $nowMinutes >= $open && $nowMinutes < $close;
    }

    public static function formatMinutesAmPm(int $totalMinutes): string
    {
        $hours24 = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;
        $period = $hours24 >= 12 ? 'PM' : 'AM';
        $hours12 = $hours24 % 12;

        if ($hours12 === 0) {
            $hours12 = 12;
        }

        return sprintf('%d:%02d %s', $hours12, $minutes, $period);
    }

    /**
     * @param  array<int, array{openMinutes: int, closeMinutes: int}|null>  $scheduleByDay
     * @param  array<int, array{label: string, days: array<int>}>  $groups
     * @return list<string>
     */
    public static function buildDisplayLines(array $scheduleByDay, array $groups): array
    {
        $lines = [];

        foreach ($groups as $group) {
            $days = $group['days'];
            $firstDay = $days[0];
            $schedule = $scheduleByDay[$firstDay] ?? null;

            if ($schedule === null) {
                $lines[] = $group['label'].': Cerrado';

                continue;
            }

            $open = self::formatMinutesAmPm((int) $schedule['openMinutes']);
            $close = self::formatMinutesAmPm((int) $schedule['closeMinutes']);
            $lines[] = $group['label'].": {$open} a {$close}";
        }

        return $lines;
    }

    /**
     * @return array<int, array{openMinutes: int, closeMinutes: int}|null>
     */
    public static function supportGeneralScheduleByDay(): array
    {
        $weekday = ['openMinutes' => (8 * 60) + 30, 'closeMinutes' => 18 * 60];

        return [
            0 => null,
            1 => $weekday,
            2 => $weekday,
            3 => $weekday,
            4 => $weekday,
            5 => $weekday,
            6 => null,
        ];
    }

    /**
     * @return array<int, array{label: string, days: array<int>}>
     */
    public static function supportGeneralDisplayGroups(): array
    {
        return [
            ['label' => 'Lunes a viernes', 'days' => [1, 2, 3, 4, 5]],
            ['label' => 'Sábado', 'days' => [6]],
            ['label' => 'Domingo', 'days' => [0]],
        ];
    }

    /**
     * @return array<int, array{label: string, days: array<int>}>
     */
    public static function conciergeDisplayGroups(): array
    {
        return [
            ['label' => 'Lunes a viernes', 'days' => [1]],
            ['label' => 'Sábado', 'days' => [6]],
            ['label' => 'Domingo', 'days' => [0]],
        ];
    }

    /**
     * @param  array<int, array{openMinutes: int, closeMinutes: int}|null>  $scheduleByDay
     * @return list<string>
     */
    public static function buildConciergeDisplayLines(array $scheduleByDay): array
    {
        return self::buildDisplayLines($scheduleByDay, self::conciergeDisplayGroups());
    }

    /**
     * @return list<string>
     */
    public static function buildSupportGeneralDisplayLines(): array
    {
        return self::buildDisplayLines(
            self::supportGeneralScheduleByDay(),
            self::supportGeneralDisplayGroups(),
        );
    }
}
