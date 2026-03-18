<?php

namespace App\Jobs;

use App\Models\LaboratoryPurchase;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendLaboratoryPurchaseToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public LaboratoryPurchase $purchase;

    public function __construct(LaboratoryPurchase $purchase)
    {
        $this->purchase = $purchase;
    }

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        $activeCampaignService->laboratoryPurchase($this->purchase);
    }
}

