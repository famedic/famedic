<?php

namespace App\Actions\InvoiceRequests;

use App\Enums\InvoiceRequestStatusLogTrigger;
use App\Enums\InvoiceRequestWorkflowStatus;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ActivateLaboratoryInvoiceRequestAction
{
    public function __construct(
        private readonly RecordInvoiceRequestStatusLogAction $recordStatusLog,
        private readonly NotifyLaboratoryBillingTeamAction $notifyLaboratoryBillingTeam,
    ) {}

    public function execute(
        InvoiceRequest $invoiceRequest,
        InvoiceRequestStatusLogTrigger|string $activatedBy,
        ?Carbon $sampleCompletedAt = null,
    ): InvoiceRequest {
        $trigger = $activatedBy instanceof InvoiceRequestStatusLogTrigger
            ? $activatedBy
            : InvoiceRequestStatusLogTrigger::from($activatedBy);

        if (! $trigger->isActivationTrigger()) {
            throw new InvalidArgumentException('El origen de activación no es válido.');
        }

        $invoiceRequestId = $invoiceRequest->id;

        $result = DB::transaction(function () use ($invoiceRequestId, $trigger, $sampleCompletedAt) {
            $locked = InvoiceRequest::query()
                ->whereKey($invoiceRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isCancelled()) {
                return $locked;
            }

            if (! $this->isAlreadyActivated($locked)) {
                $fromStatus = $locked->workflow_status;
                $updateData = [
                    'workflow_status' => InvoiceRequestWorkflowStatus::SubmittedToBilling->value,
                    'submitted_to_billing_at' => now(),
                    'activated_by' => $trigger->value,
                ];

                if ($trigger === InvoiceRequestStatusLogTrigger::SampleWebhook) {
                    $updateData['sample_completed_at'] = $sampleCompletedAt ?? now();
                }

                $locked->update($updateData);

                ($this->recordStatusLog)(
                    invoiceRequest: $locked,
                    fromStatus: $fromStatus,
                    toStatus: InvoiceRequestWorkflowStatus::SubmittedToBilling,
                    trigger: $trigger,
                );
            }

            return $locked->fresh();
        });

        DB::afterCommit(function () use ($result) {
            $this->ensureBillingTeamNotified($result);
        });

        return $result->fresh();
    }

    private function ensureBillingTeamNotified(InvoiceRequest $invoiceRequest): void
    {
        DB::transaction(function () use ($invoiceRequest) {
            $locked = InvoiceRequest::query()
                ->whereKey($invoiceRequest->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null
                || $locked->billing_team_notified_at !== null
                || ! $locked->isSubmittedToBilling()
                || $locked->invoice_requestable_type !== LaboratoryPurchase::class) {
                return;
            }

            $purchase = $locked->invoiceRequestable;

            if (! $purchase instanceof LaboratoryPurchase) {
                return;
            }

            try {
                $sent = $this->notifyLaboratoryBillingTeam->execute($purchase, $locked);
            } catch (\Throwable) {
                return;
            }

            if ($sent) {
                $locked->update(['billing_team_notified_at' => now()]);
            }
        });
    }

    private function isAlreadyActivated(InvoiceRequest $invoiceRequest): bool
    {
        if ($invoiceRequest->submitted_to_billing_at !== null) {
            return true;
        }

        return $invoiceRequest->workflow_status === InvoiceRequestWorkflowStatus::SubmittedToBilling;
    }
}
