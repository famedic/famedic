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

class SendContactToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Contact $contact;

    /**
     * Número de reintentos
     */
    public $tries = 3;

    /**
     * Tiempo antes de reintentar
     */
    public $backoff = 30;

    public function __construct(Contact $contact)
    {
        $this->contact = $contact;
    }

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        try {

            Log::info('Enviando contacto a ActiveCampaign', [
                'contact_id' => $this->contact->id,
                'email' => $this->contact->email
            ]);

            $activeCampaignService->createOrUpdateContact([
                'email' => $this->contact->email,
                'firstName' => $this->contact->first_name,
                'lastName' => $this->contact->last_name,
                'phone' => $this->contact->phone
            ]);

        } catch (\Exception $e) {

            Log::error('Error enviando contacto a ActiveCampaign', [
                'contact_id' => $this->contact->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}