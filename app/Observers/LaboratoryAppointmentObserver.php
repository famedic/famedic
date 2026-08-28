<?php

namespace App\Observers;

use App\Jobs\SendSampleCollectedToActiveCampaignJob;
use App\Models\LaboratoryAppointment;
use App\Services\Carts\LaboratoryAppointmentConfirmationSignalService;
use Illuminate\Support\Facades\DB;

class LaboratoryAppointmentObserver
{
    public function updated(LaboratoryAppointment $appointment): void
    {
        if (! $this->wasNewlyConfirmed($appointment)) {
            return;
        }

        // Asumimos que cuando se confirma la cita se tomó la muestra
        SendSampleCollectedToActiveCampaignJob::dispatch($appointment)->afterCommit();

        DB::afterCommit(function () use ($appointment): void {
            app(LaboratoryAppointmentConfirmationSignalService::class)
                ->handleNewlyConfirmed($appointment->fresh(['cart']));
        });
    }

    private function wasNewlyConfirmed(LaboratoryAppointment $appointment): bool
    {
        return $appointment->wasChanged('confirmed_at')
            && $appointment->getOriginal('confirmed_at') === null
            && $appointment->confirmed_at !== null;
    }
}

