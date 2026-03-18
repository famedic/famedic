<?php

namespace App\Observers;

use App\Models\LaboratoryAppointment;
use App\Jobs\SendSampleCollectedToActiveCampaignJob;

class LaboratoryAppointmentObserver
{
    public function updated(LaboratoryAppointment $appointment): void
    {
        // Asumimos que cuando se confirma la cita se tomó la muestra
        $justConfirmed = $appointment->isDirty('confirmed_at') && $appointment->confirmed_at !== null;

        if ($justConfirmed) {
            SendSampleCollectedToActiveCampaignJob::dispatch($appointment);
        }
    }
}

