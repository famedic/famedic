<?php

namespace App\Actions\Admin\LaboratoryAppointments;

use App\Models\LaboratoryAppointment;

class BuildLaboratoryAppointmentConciergeOperationalAgeAction
{
    private const TIMEZONE = 'America/Monterrey';

    /**
     * @return array{status: string, label: string, color: string}
     */
    public function __invoke(LaboratoryAppointment $appointment): array
    {
        $createdAt = $appointment->created_at->timezone(self::TIMEZONE);
        $now = now(self::TIMEZONE);
        $minutes = $createdAt->diffInMinutes($now);

        if ($minutes < 5) {
            return [
                'status' => 'new',
                'label' => 'Nueva',
                'color' => 'emerald',
            ];
        }

        if ($minutes < 30) {
            return [
                'status' => 'waiting',
                'label' => 'En espera',
                'color' => 'sky',
            ];
        }

        if ($minutes < 24 * 60) {
            return [
                'status' => 'priority',
                'label' => 'Prioritaria',
                'color' => 'amber',
            ];
        }

        $days = max(1, (int) $createdAt->diffInDays($now));

        return [
            'status' => 'overdue',
            'label' => 'Atrasada · '.$days.' '.$this->dayWord($days),
            'color' => 'rose',
        ];
    }

    private function dayWord(int $days): string
    {
        return $days === 1 ? 'día' : 'días';
    }
}
