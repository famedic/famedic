<?php

namespace App\Services\LaboratoryBilling;

use App\Enums\LaboratoryBillingDocumentStatus;
use App\Enums\LaboratoryBillingStatus;
use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class LaboratoryBillingStatusResolver
{
    public function thresholdDays(): int
    {
        return max(1, (int) config('famedic.laboratory_billing.invoice_delay_threshold_days', 3));
    }

    public function thresholdDate(?CarbonInterface $now = null): Carbon
    {
        $now = Carbon::parse($now ?? now())->timezone('America/Monterrey');

        return $now->copy()->subDays($this->thresholdDays())->startOfDay()->utc();
    }

    public function hasPdf(?Invoice $invoice): bool
    {
        return filled($invoice?->getRawOriginal('invoice') ?? $invoice?->getAttributes()['invoice'] ?? null);
    }

    public function hasXml(?Invoice $invoice): bool
    {
        return filled($invoice?->getRawOriginal('invoice_xml') ?? $invoice?->getAttributes()['invoice_xml'] ?? null);
    }

    public function documentStatus(?Invoice $invoice): LaboratoryBillingDocumentStatus
    {
        if (! $invoice) {
            return LaboratoryBillingDocumentStatus::NoDocuments;
        }

        $hasPdf = $this->hasPdf($invoice);
        $hasXml = $this->hasXml($invoice);

        if ($hasPdf && $hasXml) {
            return LaboratoryBillingDocumentStatus::Complete;
        }

        if ($hasXml && ! $hasPdf) {
            return LaboratoryBillingDocumentStatus::MissingPdf;
        }

        if ($hasPdf && ! $hasXml) {
            return LaboratoryBillingDocumentStatus::MissingXml;
        }

        return LaboratoryBillingDocumentStatus::NoDocuments;
    }

    public function isComplete(?Invoice $invoice): bool
    {
        if (! $invoice) {
            return false;
        }

        if ($invoice->completed_at) {
            return true;
        }

        return $this->documentStatus($invoice) === LaboratoryBillingDocumentStatus::Complete;
    }

    public function isIncompleteInvoice(?Invoice $invoice): bool
    {
        if (! $invoice) {
            return false;
        }

        return ! $this->isComplete($invoice);
    }

    public function daysElapsed(?CarbonInterface $requestedAt, ?CarbonInterface $now = null): ?int
    {
        if (! $requestedAt) {
            return null;
        }

        $now = Carbon::parse($now ?? now())->timezone('America/Monterrey')->startOfDay();
        $start = Carbon::parse($requestedAt)->timezone('America/Monterrey')->startOfDay();

        return max(0, (int) $start->diffInDays($now));
    }

    public function daysOverdue(?CarbonInterface $requestedAt, ?Invoice $invoice, ?CarbonInterface $now = null): ?int
    {
        if ($this->isComplete($invoice) || ! $requestedAt) {
            return null;
        }

        $elapsed = $this->daysElapsed($requestedAt, $now);

        if ($elapsed === null) {
            return null;
        }

        $overdue = $elapsed - $this->thresholdDays();

        return $overdue > 0 ? $overdue : 0;
    }

    public function isOverdue(?CarbonInterface $requestedAt, ?Invoice $invoice, ?CarbonInterface $now = null): bool
    {
        if ($this->isComplete($invoice) || ! $requestedAt) {
            return false;
        }

        return ($this->daysOverdue($requestedAt, $invoice, $now) ?? 0) > 0;
    }

    /**
     * Estado administrativo visible (prioridad: completada > atrasada > en proceso > pendiente).
     */
    public function resolve(?InvoiceRequest $request, ?Invoice $invoice, ?CarbonInterface $now = null): LaboratoryBillingStatus
    {
        if ($this->isComplete($invoice)) {
            return LaboratoryBillingStatus::Completed;
        }

        if ($this->isOverdue($request?->created_at, $invoice, $now)) {
            return LaboratoryBillingStatus::Overdue;
        }

        if ($invoice) {
            return LaboratoryBillingStatus::InProgress;
        }

        return LaboratoryBillingStatus::Pending;
    }

    public function responseTimeHours(?InvoiceRequest $request, ?Invoice $invoice): ?float
    {
        if (! $request?->created_at || ! $invoice?->completed_at) {
            return null;
        }

        $hours = $request->created_at->diffInMinutes($invoice->completed_at) / 60;

        return round(max(0, $hours), 2);
    }

    public function responseTimeDays(?InvoiceRequest $request, ?Invoice $invoice): ?float
    {
        $hours = $this->responseTimeHours($request, $invoice);

        return $hours === null ? null : round($hours / 24, 2);
    }

    /**
     * @param  Builder<\App\Models\InvoiceRequest>  $query
     */
    public function scopeLaboratoryRequests(Builder $query): Builder
    {
        return $query->where('invoice_requestable_type', LaboratoryPurchase::class);
    }

    /**
     * @param  Builder<\App\Models\InvoiceRequest>  $query
     */
    public function scopeComplete(Builder $query): Builder
    {
        return $query->whereHasMorph(
            'invoiceRequestable',
            [LaboratoryPurchase::class],
            function (Builder $purchaseQuery) {
                $purchaseQuery->withTrashed()->whereHas('invoice', function (Builder $invoiceQuery) {
                    $invoiceQuery->whereNotNull('completed_at');
                });
            }
        );
    }

    /**
     * @param  Builder<\App\Models\InvoiceRequest>  $query
     */
    public function scopeIncompleteDocuments(Builder $query): Builder
    {
        return $query->whereHasMorph(
            'invoiceRequestable',
            [LaboratoryPurchase::class],
            function (Builder $purchaseQuery) {
                $purchaseQuery->withTrashed()->whereHas('invoice', function (Builder $invoiceQuery) {
                    $invoiceQuery->whereNull('completed_at');
                });
            }
        );
    }

    /**
     * @param  Builder<\App\Models\InvoiceRequest>  $query
     */
    public function scopeWithoutInvoice(Builder $query): Builder
    {
        return $query->whereHasMorph(
            'invoiceRequestable',
            [LaboratoryPurchase::class],
            function (Builder $purchaseQuery) {
                $purchaseQuery->withTrashed()->whereDoesntHave('invoice');
            }
        );
    }

    /**
     * @param  Builder<\App\Models\InvoiceRequest>  $query
     */
    public function scopeNotComplete(Builder $query): Builder
    {
        return $query->whereDoesntHaveMorph(
            'invoiceRequestable',
            [LaboratoryPurchase::class],
            function (Builder $purchaseQuery) {
                $purchaseQuery->withTrashed()->whereHas('invoice', function (Builder $invoiceQuery) {
                    $invoiceQuery->whereNotNull('completed_at');
                });
            }
        );
    }

    /**
     * @param  Builder<\App\Models\InvoiceRequest>  $query
     */
    public function scopePending(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $thresholdDate = $this->thresholdDate($now);

        return $this->scopeWithoutInvoice($query)
            ->where('created_at', '>=', $thresholdDate);
    }

    /**
     * @param  Builder<\App\Models\InvoiceRequest>  $query
     */
    public function scopeInProgress(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $thresholdDate = $this->thresholdDate($now);

        return $this->scopeIncompleteDocuments($query)
            ->where('created_at', '>=', $thresholdDate);
    }

    /**
     * @param  Builder<\App\Models\InvoiceRequest>  $query
     */
    public function scopeOverdue(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $thresholdDate = $this->thresholdDate($now);

        return $this->scopeNotComplete($query)
            ->where('created_at', '<', $thresholdDate);
    }

    /**
     * @return array{status: string, status_label: string, status_color: string, document_status: string, document_status_label: string, document_status_color: string, days_elapsed: int|null, days_overdue: int|null, response_time_hours: float|null, response_time_days: float|null, has_pdf: bool, has_xml: bool, is_overdue: bool}
     */
    public function present(?InvoiceRequest $request, ?Invoice $invoice, ?CarbonInterface $now = null): array
    {
        $status = $this->resolve($request, $invoice, $now);
        $documentStatus = $this->documentStatus($invoice);

        return [
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_color' => $status->color(),
            'document_status' => $documentStatus->value,
            'document_status_label' => $documentStatus->label(),
            'document_status_color' => $documentStatus->color(),
            'days_elapsed' => $this->daysElapsed($request?->created_at, $now),
            'days_overdue' => $this->daysOverdue($request?->created_at, $invoice, $now),
            'response_time_hours' => $this->responseTimeHours($request, $invoice),
            'response_time_days' => $this->responseTimeDays($request, $invoice),
            'has_pdf' => $this->hasPdf($invoice),
            'has_xml' => $this->hasXml($invoice),
            'is_overdue' => $this->isOverdue($request?->created_at, $invoice, $now),
        ];
    }
}
