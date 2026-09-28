<?php

namespace App\Actions\Laboratories;

use App\Actions\InvoiceRequests\AttemptActivateLaboratoryInvoiceRequestForPurchaseAction;
use App\Enums\InvoiceRequestStatusLogTrigger;
use App\Enums\LaboratoryResultEventType;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryResultEvent;
use App\Services\Laboratory\LaboratoryResultsNotificationService;
use App\Services\Laboratory\LabOrderNotificationGateService;
use App\Services\InvoiceRequests\LaboratoryInvoiceRequestActivationEvaluator;
use App\Services\LaboratoryResults\LaboratoryResultCompletionGate;
use Illuminate\Support\Facades\Log;

class AttemptReleaseLaboratoryResultsNotificationAction
{
    public function __construct(
        private LabOrderNotificationGateService $notificationGateService,
        private LaboratoryResultsNotificationService $notificationService,
        private LaboratoryResultCompletionGate $completionGate,
        private LaboratoryInvoiceRequestActivationEvaluator $invoiceRequestActivationEvaluator,
        private AttemptActivateLaboratoryInvoiceRequestForPurchaseAction $attemptActivateLaboratoryInvoiceRequest,
    ) {}

    public function execute(LaboratoryPurchase $purchase, string $source = 'semantic_completion'): bool
    {
        if ($this->completionGate->mode() !== LaboratoryResultCompletionGate::MODE_ENFORCED) {
            return false;
        }

        $purchase->loadMissing(['customer.user']);
        $notification = $this->latestResultsNotification($purchase);

        if (! $notification) {
            return false;
        }

        $gdaOrderId = $notification->gda_order_id ?: $purchase->gda_order_id;

        if (! $this->completionGate->shouldAllowNotification($purchase, legacyReady: true, gdaOrderId: (string) $gdaOrderId)) {
            return false;
        }

        $sent = $this->notificationGateService->sendResultsOnce((string) $gdaOrderId, function () use ($purchase, $notification, $gdaOrderId, $source): void {
            $this->notificationService->notifyPatient(
                user: $purchase->customer?->user,
                notification: $notification,
                quote: null,
                purchase: $purchase,
                gdaOrderId: (string) $gdaOrderId,
                hasPdfInPayload: false,
            );
            $this->notificationService->enqueueActiveCampaignResultsCompleted($purchase);
            $this->recordReleaseEvent($purchase, $source);
        });

        if ($sent) {
            Log::info('laboratory_result_patient_notification_requested', [
                'purchase_id' => $purchase->id,
                'gda_order_id' => $gdaOrderId,
                'source' => $source,
            ]);
        }

        if ($this->invoiceRequestActivationEvaluator->shouldActivateByResultAvailable($purchase->fresh())) {
            $this->attemptActivateLaboratoryInvoiceRequest->execute(
                $purchase->fresh(['invoiceRequest']),
                InvoiceRequestStatusLogTrigger::ResultAvailable,
            );
        }

        return $sent;
    }

    private function latestResultsNotification(LaboratoryPurchase $purchase): ?LaboratoryNotification
    {
        return LaboratoryNotification::latestResultsForOrder(
            $purchase->id,
            $purchase->gda_order_id,
            $purchase->gda_consecutivo
        );
    }

    private function recordReleaseEvent(LaboratoryPurchase $purchase, string $source): void
    {
        $status = $purchase->laboratoryResultStatuses()->oldest('id')->first();

        if (! $status) {
            return;
        }

        LaboratoryResultEvent::query()->create([
            'laboratory_result_status_id' => $status->id,
            'event_type' => LaboratoryResultEventType::PatientNotificationRequested,
            'metadata' => [
                'source' => $source,
                'mode' => LaboratoryResultCompletionGate::MODE_ENFORCED,
            ],
            'created_at' => now(),
        ]);
    }
}
