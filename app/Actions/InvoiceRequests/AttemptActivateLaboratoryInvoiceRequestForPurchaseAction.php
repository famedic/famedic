<?php

namespace App\Actions\InvoiceRequests;

use App\Enums\InvoiceRequestStatusLogTrigger;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Services\InvoiceRequests\LaboratoryInvoiceRequestActivationEvaluator;

class AttemptActivateLaboratoryInvoiceRequestForPurchaseAction
{
    public function __construct(
        private readonly LaboratoryInvoiceRequestActivationEvaluator $evaluator,
        private readonly ActivateLaboratoryInvoiceRequestAction $activateLaboratoryInvoiceRequest,
    ) {}

    public function execute(
        LaboratoryPurchase $purchase,
        ?InvoiceRequestStatusLogTrigger $preferredTrigger = null,
    ): ?InvoiceRequest {
        $purchase->loadMissing('invoiceRequest');
        $invoiceRequest = $purchase->invoiceRequest;

        if ($invoiceRequest === null || ! $invoiceRequest->isAwaitingSampleCollection()) {
            return $invoiceRequest;
        }

        if (
            ($preferredTrigger === InvoiceRequestStatusLogTrigger::SampleWebhook
                || $preferredTrigger === null)
            && $this->evaluator->shouldActivateBySampleComplete($purchase)
        ) {
            return $this->activateLaboratoryInvoiceRequest->execute(
                invoiceRequest: $invoiceRequest,
                activatedBy: InvoiceRequestStatusLogTrigger::SampleWebhook,
                sampleCompletedAt: $this->evaluator->resolveSampleCompletedAt($purchase),
            );
        }

        if (
            ($preferredTrigger === InvoiceRequestStatusLogTrigger::ResultAvailable
                || $preferredTrigger === null)
            && $this->evaluator->shouldActivateByResultAvailable($purchase)
        ) {
            return $this->activateLaboratoryInvoiceRequest->execute(
                invoiceRequest: $invoiceRequest,
                activatedBy: InvoiceRequestStatusLogTrigger::ResultAvailable,
            );
        }

        return $invoiceRequest;
    }
}
