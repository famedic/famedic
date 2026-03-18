<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPatientCreatedToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Contact $contact)
    {
    }

    public function handle(ActiveCampaignService $service)
    {
        $service->patientCreated($this->contact);
    }
}