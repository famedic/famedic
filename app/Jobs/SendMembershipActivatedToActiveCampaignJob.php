<?php

namespace App\Jobs;

use App\Models\MedicalAttentionSubscription;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMembershipActivatedToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public MedicalAttentionSubscription $subscription;

    public function __construct(MedicalAttentionSubscription $subscription)
    {
        $this->subscription = $subscription;
    }

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        $email = $this->subscription->customer->user->email;
        $activeCampaignService->membershipActivated($email);
    }
}

