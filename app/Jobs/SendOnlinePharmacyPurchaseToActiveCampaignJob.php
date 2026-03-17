<?php

namespace App\Jobs;

use App\Models\OnlinePharmacyPurchase;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOnlinePharmacyPurchaseToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public OnlinePharmacyPurchase $purchase;

    public function __construct(OnlinePharmacyPurchase $purchase)
    {
        $this->purchase = $purchase;
    }

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        $activeCampaignService->pharmacyPurchase($this->purchase);
    }
}

