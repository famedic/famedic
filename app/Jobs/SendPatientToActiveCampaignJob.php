<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPatientToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(public Contact $contact)
    {
    }

    public function handle(ActiveCampaignService $service)
    {
        $customer = $this->contact->customer;

        $service->trackEvent(
            email: $customer->email,
            event: 'paciente_agregado',
            data: [
                'patient_name' => $this->contact->name,
                'patient_lastname' => $this->contact->paternal_lastname,
                'gender' => $this->contact->gender,
                'birth_date' => $this->contact->birth_date
            ]
        );
    }
}