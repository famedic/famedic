<?php

namespace App\Jobs;

use App\Models\LaboratoryAppointment;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSampleCollectedToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public LaboratoryAppointment $appointment;

    public function __construct(LaboratoryAppointment $appointment)
    {
        $this->appointment = $appointment;
    }

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        $email = $this->appointment->customer->user->email ?? null;

        if (! $email) {
            return;
        }

        $activeCampaignService->sampleCollected($email);
    }
}

