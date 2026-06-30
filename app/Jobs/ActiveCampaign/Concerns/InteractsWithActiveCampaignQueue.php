<?php

namespace App\Jobs\ActiveCampaign\Concerns;

use App\Services\ActiveCampaign\ActiveCampaignDispatchService;
use Illuminate\Support\Facades\Log;

trait InteractsWithActiveCampaignQueue
{
    public int $tries = 5;

    public int $backoff = 60;

    public int $timeout = 120;

    /**
     * @return array<string, mixed>
     */
    protected function activeCampaignLogContext(string $eventType, ?int $dispatchId = null): array
    {
        return array_filter([
            'ac_job' => static::class,
            'event_type' => $eventType,
            'dispatch_id' => $dispatchId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function sanitizeActiveCampaignPayload(array $payload): array
    {
        return app(ActiveCampaignDispatchService::class)->sanitizePayloadForLog($payload);
    }

    protected function logActiveCampaignJobStart(string $eventType, ?int $dispatchId = null, array $extra = []): void
    {
        Log::info('AC Job: started', array_merge(
            $this->activeCampaignLogContext($eventType, $dispatchId),
            $extra
        ));
    }

    protected function logActiveCampaignJobFailure(string $eventType, ?int $dispatchId, string $message): void
    {
        Log::error('AC Job: failed', array_merge(
            $this->activeCampaignLogContext($eventType, $dispatchId),
            ['error' => $message]
        ));
    }
}
