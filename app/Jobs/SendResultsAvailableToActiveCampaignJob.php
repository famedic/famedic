<?php

namespace App\Jobs;

use App\Models\LaboratoryNotification;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendResultsAvailableToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public LaboratoryNotification $notification;

    public function __construct(LaboratoryNotification $notification)
    {
        $this->notification = $notification;
    }

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        if (! $this->notification->hasUser()) {
            return;
        }

        $email = $this->notification->user?->email;

        if (! $email) {
            return;
        }

        $activeCampaignService->resultsAvailable($email);
    }
}

