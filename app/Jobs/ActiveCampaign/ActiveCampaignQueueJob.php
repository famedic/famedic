<?php

namespace App\Jobs\ActiveCampaign;

use App\Jobs\ActiveCampaign\Concerns\InteractsWithActiveCampaignQueue;
use App\Models\ActiveCampaignDispatch;
use App\Services\ActiveCampaign\ActiveCampaignDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class ActiveCampaignQueueJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithActiveCampaignQueue;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $dispatchId,
    ) {
        $queue = config('services.activecampaign.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    protected function resolveDispatch(): ?ActiveCampaignDispatch
    {
        return ActiveCampaignDispatch::query()->find($this->dispatchId);
    }

    protected function dispatchService(): ActiveCampaignDispatchService
    {
        return app(ActiveCampaignDispatchService::class);
    }
}
