<?php

namespace App\Jobs;

use App\Models\LaboratoryCartItem;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCartAddedToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public LaboratoryCartItem $item;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(LaboratoryCartItem $item)
    {
        $this->item = $item;
    }

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        if (! app()->isProduction()) {
            Log::info('AC: SendCartAddedToActiveCampaignJob omitido — solo corre en production', [
                'environment' => app()->environment(),
                'cart_item_id' => $this->item->id,
            ]);

            return;
        }

        if (! config('services.activecampaign.enabled')) {
            Log::info('AC: SendCartAddedToActiveCampaignJob omitido — integración desactivada', [
                'cart_item_id' => $this->item->id,
            ]);

            return;
        }

        $this->item->loadMissing(['customer.user']);

        $email = $this->item->customer?->user?->email;

        if (blank($email)) {
            Log::warning('AC: SendCartAddedToActiveCampaignJob omitido — email no disponible', [
                'cart_item_id' => $this->item->id,
                'customer_id' => $this->item->customer_id,
            ]);

            return;
        }

        Log::info('AC: SendCartAddedToActiveCampaignJob iniciado', [
            'cart_item_id' => $this->item->id,
            'email' => $email,
        ]);

        try {
            $activeCampaignService->cartAdded($email);

            Log::info('AC: SendCartAddedToActiveCampaignJob completado', [
                'cart_item_id' => $this->item->id,
                'email' => $email,
            ]);
        } catch (\Throwable $e) {
            Log::error('AC: Error en SendCartAddedToActiveCampaignJob', [
                'cart_item_id' => $this->item->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
