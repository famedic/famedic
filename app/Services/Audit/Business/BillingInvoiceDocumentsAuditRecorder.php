<?php

namespace App\Services\Audit\Business;

use App\Models\Audit\BusinessAuditEvent;

/**
 * Domain recorder for billing.invoice_completed and billing.invoice_documents_replaced (Block 7B).
 *
 * Emit only after CreateInvoiceAction commits. completed is soft-deduped per invoice;
 * documents_replaced appends once per successful replacement. Fail-soft via writer.
 */
final class BillingInvoiceDocumentsAuditRecorder
{
    public function __construct(
        private readonly BusinessAuditEventWriter $writer,
    ) {}

    public function recordCompleted(
        int $invoiceId,
        BillingInvoiceDocumentsAuditHint $hint,
    ): ?BusinessAuditEvent {
        if ($invoiceId < 1) {
            return null;
        }

        if (! $this->writer->enabled()) {
            return null;
        }

        $resourceKey = (string) $invoiceId;

        try {
            if ($this->alreadyRecordedCompleted($resourceKey)) {
                return null;
            }

            $context = $this->buildContext($hint);
            if ($context === null) {
                return null;
            }

            return $this->writer->write([
                'event_name' => BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED,
                'outcome' => BusinessAuditOutcome::SUCCEEDED,
                'context' => $context,
                'resource_type' => 'invoice',
                'resource_key' => $resourceKey,
                'correlation_id' => $hint->correlationId,
                'metadata' => [
                    'purchase_type' => $hint->purchaseType,
                    'purchase_id' => $hint->purchaseId,
                ],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function recordDocumentsReplaced(
        int $invoiceId,
        BillingInvoiceDocumentsAuditHint $hint,
        bool $pdfReplaced,
        bool $xmlReplaced,
    ): ?BusinessAuditEvent {
        if ($invoiceId < 1) {
            return null;
        }

        if (! $pdfReplaced && ! $xmlReplaced) {
            return null;
        }

        if (! $this->writer->enabled()) {
            return null;
        }

        $resourceKey = (string) $invoiceId;

        try {
            $context = $this->buildContext($hint);
            if ($context === null) {
                return null;
            }

            return $this->writer->write([
                'event_name' => BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_DOCUMENTS_REPLACED,
                'outcome' => BusinessAuditOutcome::SUCCEEDED,
                'context' => $context,
                'resource_type' => 'invoice',
                'resource_key' => $resourceKey,
                'correlation_id' => $hint->correlationId,
                'metadata' => [
                    'purchase_type' => $hint->purchaseType,
                    'purchase_id' => $hint->purchaseId,
                    'pdf_replaced' => $pdfReplaced,
                    'xml_replaced' => $xmlReplaced,
                ],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function alreadyRecordedCompleted(string $resourceKey): bool
    {
        return BusinessAuditEvent::query()
            ->where('event_name', BusinessAuditEventDefinitions::EVENT_BILLING_INVOICE_COMPLETED)
            ->where('resource_type', 'invoice')
            ->where('resource_key', $resourceKey)
            ->exists();
    }

    private function buildContext(BillingInvoiceDocumentsAuditHint $hint): ?BusinessAuditContext
    {
        if (! BusinessAuditChannel::isValid($hint->channel)) {
            return null;
        }

        if (! BillingInvoiceDocumentsAuditHint::isValidPurchaseType($hint->purchaseType)) {
            return null;
        }

        if ($hint->purchaseId < 1 || $hint->actorAdminUserId < 1) {
            return null;
        }

        $actor = BusinessAuditActor::admin($hint->actorAdminUserId);

        $subject = null;
        if ($hint->subjectCustomerId !== null && $hint->subjectCustomerId > 0) {
            $subject = BusinessAuditSubject::customer($hint->subjectCustomerId);
        }

        return new BusinessAuditContext(
            channel: $hint->channel,
            actor: $actor,
            correlationId: $hint->correlationId,
            subject: $subject,
        );
    }
}
