<?php

namespace App\Services\Audit\Business;

use App\Models\Audit\BusinessAuditEvent;

/**
 * Domain recorder for billing.invoice_requested (Block 7B).
 *
 * Emit only after the invoice-request domain transaction has committed and
 * only for first-time creates (not updates). Fail-soft via writer. Soft-dedupes
 * by resource_key so re-entry for the same invoice_request does not append again.
 */
final class BillingInvoiceRequestedAuditRecorder
{
    public function __construct(
        private readonly BusinessAuditEventWriter $writer,
    ) {}

    public function recordSucceeded(
        int $invoiceRequestId,
        BillingInvoiceRequestedAuditHint $hint,
    ): ?BusinessAuditEvent {
        if ($invoiceRequestId < 1) {
            return null;
        }

        if (! $this->writer->enabled()) {
            return null;
        }

        $resourceKey = (string) $invoiceRequestId;

        try {
            if ($this->alreadyRecorded($resourceKey)) {
                return null;
            }

            if (! BusinessAuditChannel::isValid($hint->channel)) {
                return null;
            }

            if (! BillingInvoiceRequestedAuditHint::isValidOrigin($hint->requestOrigin)) {
                return null;
            }

            if (! BillingInvoiceRequestedAuditHint::isValidPurchaseType($hint->purchaseType)) {
                return null;
            }

            if ($hint->purchaseId < 1 || $hint->actorCustomerId < 1) {
                return null;
            }

            $actor = BusinessAuditActor::customer(
                $hint->actorCustomerId,
                $hint->actorUserId
            );

            $subjectCustomerId = $hint->subjectCustomerId ?? $hint->actorCustomerId;
            $subject = $subjectCustomerId > 0
                ? BusinessAuditSubject::customer($subjectCustomerId)
                : null;

            $context = new BusinessAuditContext(
                channel: $hint->channel,
                actor: $actor,
                correlationId: $hint->correlationId,
                subject: $subject,
            );

            return $this->writer->write([
                'event_name' => BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_REQUESTED,
                'outcome' => BusinessAuditOutcome::SUCCEEDED,
                'context' => $context,
                'resource_type' => 'invoice_request',
                'resource_key' => $resourceKey,
                'correlation_id' => $hint->correlationId,
                'metadata' => [
                    'request_origin' => $hint->requestOrigin,
                    'purchase_type' => $hint->purchaseType,
                    'purchase_id' => $hint->purchaseId,
                ],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function alreadyRecorded(string $resourceKey): bool
    {
        return BusinessAuditEvent::query()
            ->where('event_name', BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_REQUESTED)
            ->where('resource_type', 'invoice_request')
            ->where('resource_key', $resourceKey)
            ->exists();
    }
}
