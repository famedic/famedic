<?php

namespace App\Services\LaboratoryBilling;

use App\Models\LaboratoryPurchase;
use App\Services\InvoiceRequests\LaboratoryInvoiceRequestActivationEvaluator;
use App\Services\Laboratory\LabOrderNotificationGateService;
use App\Services\LaboratoryResults\LaboratoryPurchaseResultCompletionService;
use Illuminate\Support\Carbon;

class LaboratoryBillingInvoiceRequestContextPresenter
{
    public function __construct(
        private LaboratoryInvoiceRequestActivationEvaluator $activationEvaluator,
        private LabOrderNotificationGateService $notificationGateService,
        private LaboratoryPurchaseResultCompletionService $resultCompletionService,
    ) {}

    /**
     * @return array{received: int, expected: int, label: string, is_complete: bool}
     */
    public function presentSampleCollectionProgress(LaboratoryPurchase $purchase): array
    {
        $purchase->loadMissing('laboratoryPurchaseItems');
        $expected = max(1, (int) $purchase->laboratoryPurchaseItems->count());
        $state = $this->activationEvaluator->resolveEventState($purchase);

        if ($state === null) {
            return [
                'received' => 0,
                'expected' => $expected,
                'label' => "0 / {$expected} muestras",
                'is_complete' => false,
            ];
        }

        $expected = $this->notificationGateService->expectedStudiesForState($state);
        $received = (int) $state->sample_received_count;
        $isComplete = $this->notificationGateService->areSamplesComplete($state);

        return [
            'received' => $received,
            'expected' => $expected,
            'label' => $isComplete
                ? "{$expected} / {$expected} — Completo"
                : "{$received} / {$expected} muestras",
            'is_complete' => $isComplete,
        ];
    }

    /**
     * @return array{label: string, status: string}
     */
    public function presentResultAvailability(LaboratoryPurchase $purchase): array
    {
        $completion = $this->resultCompletionService->evaluate($purchase);

        if ($completion->isComplete) {
            return [
                'label' => 'Completo',
                'status' => 'complete',
            ];
        }

        $state = $this->activationEvaluator->resolveEventState($purchase);

        if ($state !== null && $this->notificationGateService->areResultsComplete($state)) {
            return [
                'label' => 'Completo',
                'status' => 'complete',
            ];
        }

        if ($state !== null && $state->results_received_count > 0) {
            return [
                'label' => 'Parcial',
                'status' => 'partial',
            ];
        }

        if (filled($purchase->results) || $purchase->hasResultsAvailable()) {
            return [
                'label' => 'Disponible',
                'status' => 'available',
            ];
        }

        return [
            'label' => 'Pendiente',
            'status' => 'pending',
        ];
    }

    public function presentLastActivityAt(LaboratoryPurchase $purchase): ?string
    {
        $candidates = collect([
            $this->activationEvaluator->resolveEventState($purchase)?->last_event_at,
            $purchase->ready_at,
            $purchase->results_downloaded_at,
            $purchase->completed_at,
            optional($purchase->latestSampleCollection())->created_at,
            optional($purchase->latestResultsNotification())->created_at,
        ])->filter();

        /** @var Carbon|null $latest */
        $latest = $candidates->sortByDesc(fn ($date) => Carbon::parse($date)->timestamp)->first();

        return $latest
            ? localizedDate($latest)?->isoFormat('D MMM Y h:mm a')
            : null;
    }
}
