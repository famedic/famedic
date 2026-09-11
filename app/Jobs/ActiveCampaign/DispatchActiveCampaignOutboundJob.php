<?php

namespace App\Jobs\ActiveCampaign;

use App\Enums\ActiveCampaignSiteEvent;
use App\Exceptions\ActiveCampaignSyncException;
use App\Models\ActiveCampaignDispatch;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchActiveCampaignOutboundJob extends ActiveCampaignQueueJob
{
    /** @var list<string> */
    private const IMPLEMENTED_EVENT_TYPES = [
        'cart_abandoned',
        'cart_resumed',
        'cart_recovered',
    ];

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        $dispatch = $this->resolveDispatch();

        if ($dispatch === null) {
            return;
        }

        $this->logActiveCampaignJobStart(
            $dispatch->event_type,
            $dispatch->id,
            ['payload' => $this->sanitizeActiveCampaignPayload($dispatch->payload ?? [])]
        );

        if (! $this->dispatchService()->isEnabled()) {
            $this->dispatchService()->markSkipped($dispatch, 'integration_disabled');

            return;
        }

        if ($dispatch->status === ActiveCampaignDispatch::STATUS_SKIPPED) {
            return;
        }

        if ($dispatch->status === ActiveCampaignDispatch::STATUS_SYNCED) {
            return;
        }

        $payload = $dispatch->payload ?? [];
        $operation = (string) ($payload['operation'] ?? '');

        if ($operation === 'site_event') {
            if (! $this->dispatchService()->isCartSiteEventsEnabled()) {
                $this->dispatchService()->markSkipped($dispatch, 'cart_site_events_disabled');

                return;
            }
        } elseif ($operation === 'tag_remove' && ! $this->dispatchService()->isCartTagRemoveEnabled()) {
            $this->dispatchService()->markSkipped($dispatch, 'cart_tag_remove_disabled');

            return;
        } elseif (! in_array($dispatch->event_type, self::IMPLEMENTED_EVENT_TYPES, true)
            && ! in_array($operation, ['tag_add', 'tag_remove'], true)
            && ! $this->isCartSiteEventDispatch($dispatch)) {
            $this->dispatchService()->markSkipped($dispatch, 'event_not_implemented');

            return;
        }

        $this->dispatchService()->markProcessing($dispatch);

        try {
            match ($operation) {
                'tag_add' => $activeCampaignService->handleOutboundCartTagAdd($payload),
                'tag_remove' => $activeCampaignService->handleOutboundCartTagRemove($payload),
                'site_event' => $activeCampaignService->handleOutboundCartSiteEvent($payload),
                default => match ($dispatch->event_type) {
                    'cart_abandoned' => $activeCampaignService->handleOutboundCartTagAdd(array_merge($payload, [
                        'operation' => 'tag_add',
                    ])),
                    default => throw new ActiveCampaignSyncException('Operacion AC outbound no soportada: '.$operation),
                },
            };

            $this->dispatchService()->markSynced($dispatch);
            $this->markLegacyCartAbandonedTaggedAt($dispatch);
        } catch (Throwable $e) {
            $this->dispatchService()->markFailed($dispatch, $e);
            $this->logActiveCampaignJobFailure($dispatch->event_type, $dispatch->id, $e->getMessage());

            throw $e;
        }
    }

    private function isCartSiteEventDispatch(ActiveCampaignDispatch $dispatch): bool
    {
        return in_array($dispatch->event_type, ActiveCampaignSiteEvent::values(), true)
            || str_starts_with($dispatch->event_type, 'famedic_cart_');
    }

    private function markLegacyCartAbandonedTaggedAt(ActiveCampaignDispatch $dispatch): void
    {
        if ($dispatch->event_type !== 'cart_abandoned' || ! $dispatch->customer_id) {
            return;
        }

        $payload = $dispatch->payload ?? [];
        if (($payload['operation'] ?? null) !== 'tag_add') {
            return;
        }

        DB::table('customers')
            ->where('id', $dispatch->customer_id)
            ->update(['cart_abandoned_tagged_at' => now()]);
    }
}
