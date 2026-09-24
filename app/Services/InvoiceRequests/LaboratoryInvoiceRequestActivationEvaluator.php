<?php

namespace App\Services\InvoiceRequests;

use App\Models\LabOrderEventState;
use App\Models\LaboratoryPurchase;
use App\Services\Laboratory\LabOrderNotificationGateService;
use App\Services\LaboratoryResults\LaboratoryPurchaseResultCompletionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class LaboratoryInvoiceRequestActivationEvaluator
{
    public function __construct(
        private readonly LabOrderNotificationGateService $notificationGateService,
        private readonly LaboratoryPurchaseResultCompletionService $resultCompletionService,
    ) {}

    public function shouldActivateBySampleComplete(LaboratoryPurchase $purchase): bool
    {
        $state = $this->resolveEventState($purchase);

        return $state !== null
            && $this->notificationGateService->areSamplesComplete($state);
    }

    public function shouldActivateByResultAvailable(LaboratoryPurchase $purchase): bool
    {
        if ($this->isSampleCollectionInProgress($purchase)) {
            return false;
        }

        return $this->arePurchaseResultsComplete($purchase);
    }

    public function resolveSampleCompletedAt(LaboratoryPurchase $purchase): ?Carbon
    {
        $state = $this->resolveEventState($purchase);

        if ($state === null || ! $this->notificationGateService->areSamplesComplete($state)) {
            return null;
        }

        return $state->last_event_at
            ?? $state->sample_email_sent_at
            ?? now();
    }

    public function isSampleCollectionInProgress(LaboratoryPurchase $purchase): bool
    {
        $state = $this->resolveEventState($purchase);

        if ($state === null) {
            return false;
        }

        return $state->sample_received_count > 0
            && ! $this->notificationGateService->areSamplesComplete($state);
    }

    public function resolveEventState(LaboratoryPurchase $purchase): ?LabOrderEventState
    {
        if (! filled($purchase->gda_order_id) || ! Schema::hasTable('lab_order_event_states')) {
            return null;
        }

        return LabOrderEventState::query()
            ->where('gda_order_id', $purchase->gda_order_id)
            ->first();
    }

    private function arePurchaseResultsComplete(LaboratoryPurchase $purchase): bool
    {
        $state = $this->resolveEventState($purchase);

        if ($state !== null && $this->notificationGateService->areResultsComplete($state)) {
            return true;
        }

        if (! Schema::hasTable('laboratory_purchase_items')) {
            return false;
        }

        return $this->resultCompletionService->evaluate($purchase)->isComplete;
    }
}
