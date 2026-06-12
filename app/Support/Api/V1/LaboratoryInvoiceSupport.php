<?php

namespace App\Support\Api\V1;

use App\Models\LaboratoryPurchase;

class LaboratoryInvoiceSupport
{
    public function daysLeftToRequestInvoice(LaboratoryPurchase $order): int
    {
        $lastDayOfPurchaseMonth = localizedDate($order->created_at)->endOfMonth();
        $nowInMonterrey = localizedDate(now());

        if (! $nowInMonterrey->lt($lastDayOfPurchaseMonth)) {
            return 0;
        }

        return (int) ceil($nowInMonterrey->diffInDays($lastDayOfPurchaseMonth, false));
    }

    public function canRequestInvoice(LaboratoryPurchase $order): bool
    {
        if ($order->trashed()) {
            return false;
        }

        if ($order->invoice()->exists()) {
            return false;
        }

        return $this->daysLeftToRequestInvoice($order) > 0;
    }

    public function resolveInvoiceStatus(LaboratoryPurchase $order): string
    {
        if ($order->invoice) {
            return 'issued';
        }

        if ($order->invoiceRequest) {
            return 'pending';
        }

        return 'not_requested';
    }

    /**
     * @return array<string, mixed>
     */
    public function buildStatusPayload(LaboratoryPurchase $order): array
    {
        $order->loadMissing(['invoice', 'invoiceRequest']);

        $status = $this->resolveInvoiceStatus($order);

        $payload = [
            'order_id' => $order->id,
            'invoice_status' => $status,
            'invoice_request' => null,
            'invoice' => null,
        ];

        if ($order->invoiceRequest && ! $order->invoice) {
            $payload['invoice_request'] = [
                'id' => $order->invoiceRequest->id,
                'requested_at' => $order->invoiceRequest->created_at?->toIso8601String(),
            ];
        }

        if ($order->invoice) {
            $payload['invoice'] = [
                'id' => $order->invoice->id,
                'status' => 'issued',
                'issued_at' => $order->invoice->created_at?->toIso8601String(),
                'download_url' => route('invoice', ['invoice' => $order->invoice->id]),
            ];
        }

        return $payload;
    }

    public function findOwnedOrder(int $customerId, int $orderId): ?LaboratoryPurchase
    {
        return LaboratoryPurchase::query()
            ->where('customer_id', $customerId)
            ->where('id', $orderId)
            ->first();
    }

    public function findOwnedTaxProfile(int $customerId, int $taxProfileId): ?\App\Models\TaxProfile
    {
        return \App\Models\TaxProfile::query()
            ->where('customer_id', $customerId)
            ->where('id', $taxProfileId)
            ->first();
    }
}
