<?php

namespace App\Actions\Admin\LaboratoryAppointments;

use Carbon\CarbonInterface;

class BuildLaboratoryAppointmentCartActivitySignalAction
{
    private const TIMEZONE = 'America/Monterrey';

    /**
     * @return array{label: string, color: string}
     */
    public function __invoke(?CarbonInterface $lastActivityAt, ?int $priorityTier): array
    {
        if ($priorityTier === null || $priorityTier >= 4 || $lastActivityAt === null) {
            return [
                'label' => 'Sin actividad reciente',
                'color' => 'zinc',
            ];
        }

        $activityAt = $lastActivityAt->timezone(self::TIMEZONE);
        $now = now(self::TIMEZONE);
        $minutes = (int) $activityAt->diffInMinutes($now);
        $days = max(1, (int) $activityAt->diffInDays($now));

        if ($priorityTier === 1) {
            $duration = $minutes < 60
                ? "hace {$minutes} min"
                : 'hace '.$activityAt->locale('es')->diffForHumans($now, true);

            return [
                'label' => 'Actividad reciente · '.$duration,
                'color' => 'emerald',
            ];
        }

        if ($priorityTier === 2) {
            $duration = $days === 1 ? 'hace 1 día' : "hace {$days} días";

            return [
                'label' => 'Actividad esta semana · '.$duration,
                'color' => 'sky',
            ];
        }

        $duration = $days === 1 ? 'hace 1 día' : "hace {$days} días";

        return [
            'label' => 'Carrito activo · '.$duration,
            'color' => 'amber',
        ];
    }
}
