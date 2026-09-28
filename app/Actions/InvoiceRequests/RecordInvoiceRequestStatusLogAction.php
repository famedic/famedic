<?php

namespace App\Actions\InvoiceRequests;

use App\Enums\InvoiceRequestStatusLogTrigger;
use App\Enums\InvoiceRequestWorkflowStatus;
use App\Models\InvoiceRequest;
use App\Models\InvoiceRequestStatusLog;

class RecordInvoiceRequestStatusLogAction
{
    public function __invoke(
        InvoiceRequest $invoiceRequest,
        ?InvoiceRequestWorkflowStatus $fromStatus,
        InvoiceRequestWorkflowStatus $toStatus,
        InvoiceRequestStatusLogTrigger $trigger,
        array $metadata = [],
    ): InvoiceRequestStatusLog {
        return $invoiceRequest->statusLogs()->create([
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus->value,
            'trigger' => $trigger->value,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
