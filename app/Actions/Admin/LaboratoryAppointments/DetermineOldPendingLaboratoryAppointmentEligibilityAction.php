<?php

namespace App\Actions\Admin\LaboratoryAppointments;

use App\Models\LaboratoryAppointment;
use Carbon\CarbonInterface;

class DetermineOldPendingLaboratoryAppointmentEligibilityAction
{
    private const TIMEZONE = 'America/Monterrey';
    private const AGE_THRESHOLD_DAYS = 30;

    public function __invoke(LaboratoryAppointment $appointment): bool
    {
        if ($appointment->trashed()) {
            return false;
        }

        if ($appointment->confirmed_at !== null || $appointment->laboratory_purchase_id !== null) {
            return false;
        }

        return $appointment->created_at
            ->timezone(self::TIMEZONE)
            ->lt($this->cutoff());
    }

    public function cutoff(): CarbonInterface
    {
        return now(self::TIMEZONE)->subDays(self::AGE_THRESHOLD_DAYS);
    }

    public function cutoffUtc(): CarbonInterface
    {
        return $this->cutoff()->copy()->setTimezone('UTC');
    }
}
