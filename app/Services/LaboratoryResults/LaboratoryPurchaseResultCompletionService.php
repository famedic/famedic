<?php

namespace App\Services\LaboratoryResults;

use App\Enums\LaboratoryResultStatus as LaboratoryResultStatusEnum;
use App\Models\LaboratoryPurchase;

class LaboratoryPurchaseResultCompletionService
{
    public function evaluate(?LaboratoryPurchase $purchase): LaboratoryPurchaseResultCompletion
    {
        if (! $purchase?->id) {
            return new LaboratoryPurchaseResultCompletion(
                isComplete: false,
                reason: 'purchase_not_found',
                totalRequired: 0,
                complete: 0,
                pending: 0,
                manualReview: 0,
                error: 0,
                missing: 0,
                legacyFallback: false,
            );
        }

        $purchase->loadMissing(['laboratoryPurchaseItems.laboratoryResultStatus']);

        $requiredItems = $this->requiredResultItems($purchase);
        $totalRequired = $requiredItems->count();

        if ($totalRequired === 0) {
            return new LaboratoryPurchaseResultCompletion(
                isComplete: false,
                reason: 'no_required_result_items',
                totalRequired: 0,
                complete: 0,
                pending: 0,
                manualReview: 0,
                error: 0,
                missing: 0,
                legacyFallback: false,
            );
        }

        $statuses = $requiredItems
            ->map(fn ($item) => $item->laboratoryResultStatus)
            ->filter();

        if ($statuses->isEmpty()) {
            $legacyAvailable = filled($purchase->results) || $purchase->hasResultsAvailable();

            return new LaboratoryPurchaseResultCompletion(
                isComplete: $legacyAvailable,
                reason: $legacyAvailable ? 'legacy_no_result_statuses' : 'awaiting_results',
                totalRequired: $totalRequired,
                complete: 0,
                pending: 0,
                manualReview: 0,
                error: 0,
                missing: $totalRequired,
                legacyFallback: $legacyAvailable,
            );
        }

        $complete = 0;
        $pending = 0;
        $manualReview = 0;
        $error = 0;
        $missing = 0;

        foreach ($requiredItems as $item) {
            $status = $item->laboratoryResultStatus?->status;

            if ($status === LaboratoryResultStatusEnum::Complete) {
                $complete++;

                continue;
            }

            if ($status === LaboratoryResultStatusEnum::ManualReview) {
                $manualReview++;

                continue;
            }

            if ($status === LaboratoryResultStatusEnum::Error) {
                $error++;

                continue;
            }

            if (
                $status === null
                || $status === LaboratoryResultStatusEnum::NotAvailable
            ) {
                $missing++;

                continue;
            }

            if (
                $status === LaboratoryResultStatusEnum::PendingInterpretation
                || $status === LaboratoryResultStatusEnum::AvailableUnchecked
            ) {
                $pending++;

                continue;
            }

            $pending++;
        }

        $reason = match (true) {
            $manualReview > 0 => 'manual_review_required',
            $error > 0 => 'error_required',
            $missing > 0 => 'missing_result_status',
            $pending > 0 => 'pending_interpretation',
            $complete === $totalRequired => 'all_required_complete',
            default => 'incomplete',
        };

        return new LaboratoryPurchaseResultCompletion(
            isComplete: $complete === $totalRequired,
            reason: $reason,
            totalRequired: $totalRequired,
            complete: $complete,
            pending: $pending,
            manualReview: $manualReview,
            error: $error,
            missing: $missing,
            legacyFallback: false,
        );
    }

    private function requiredResultItems(LaboratoryPurchase $purchase)
    {
        $items = $purchase->laboratoryPurchaseItems;

        $withGdaId = $items->filter(fn ($item) => filled($item->gda_id));

        return $withGdaId->isNotEmpty()
            ? $withGdaId->values()
            : $items->values();
    }
}
