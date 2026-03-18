<?php

namespace App\Jobs;

use App\Models\LaboratoryCartItem;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCartAddedToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public LaboratoryCartItem $item;

    public function __construct(LaboratoryCartItem $item)
    {
        $this->item = $item;
    }

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        $email = $this->item->customer->user->email;
        $activeCampaignService->cartAdded($email);
    }
}

