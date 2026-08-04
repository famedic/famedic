<?php

namespace App\Jobs\ActiveCampaign;

use App\Exceptions\ActiveCampaignSyncException;
use App\Models\ActiveCampaignDispatch;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Throwable;

class DispatchActiveCampaignCouponEventJob extends ActiveCampaignQueueJob
{
    /** @var list<string> */
    private const IMPLEMENTED_EVENTS = [
        'credit_assigned',
        'credit_redeemed',
        'credit_restored',
        'credit_revoked',
        'credit_expiring',
        'pending_beneficiary_created',
        'pending_beneficiary_activated',
        'pending_beneficiary_cancelled',
        'promo_validated',
        'promo_used',
        'promo_released',
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

        if ($this->dispatchService()->isCouponEvent($dispatch->event_type)
            && ! $this->dispatchService()->isCouponsEnabled()) {
            $this->dispatchService()->markSkipped($dispatch, 'coupons_integration_disabled');

            return;
        }

        if ($dispatch->status === ActiveCampaignDispatch::STATUS_SKIPPED) {
            return;
        }

        if ($dispatch->status === ActiveCampaignDispatch::STATUS_SYNCED) {
            return;
        }

        if (! in_array($dispatch->event_type, self::IMPLEMENTED_EVENTS, true)) {
            $this->dispatchService()->markSkipped($dispatch, 'event_not_implemented');

            return;
        }

        $this->dispatchService()->markProcessing($dispatch);

        try {
            match ($dispatch->event_type) {
                'credit_assigned' => $activeCampaignService->handleCouponCreditAssigned($dispatch->payload ?? []),
                'credit_redeemed' => $activeCampaignService->handleCouponCreditRedeemed($dispatch->payload ?? []),
                'credit_restored' => $activeCampaignService->handleCouponCreditRestored($dispatch->payload ?? []),
                'credit_revoked' => $activeCampaignService->handleCouponCreditRevoked($dispatch->payload ?? []),
                'credit_expiring' => $activeCampaignService->handleCouponCreditExpiring($dispatch->payload ?? []),
                'pending_beneficiary_created' => $activeCampaignService->handlePendingBeneficiaryCreated($dispatch->payload ?? []),
                'pending_beneficiary_activated' => $activeCampaignService->handlePendingBeneficiaryActivated($dispatch->payload ?? []),
                'pending_beneficiary_cancelled' => $activeCampaignService->handlePendingBeneficiaryCancelled($dispatch->payload ?? []),
                'promo_validated' => $activeCampaignService->handlePromoValidated($dispatch->payload ?? []),
                'promo_used' => $activeCampaignService->handlePromoUsed($dispatch->payload ?? []),
                'promo_released' => $activeCampaignService->handlePromoReleased($dispatch->payload ?? []),
                default => throw new ActiveCampaignSyncException('Evento AC no soportado: '.$dispatch->event_type),
            };

            $this->dispatchService()->markSynced($dispatch);
        } catch (Throwable $e) {
            $this->dispatchService()->markFailed($dispatch, $e);
            $this->logActiveCampaignJobFailure($dispatch->event_type, $dispatch->id, $e->getMessage());

            throw $e;
        }
    }
}
