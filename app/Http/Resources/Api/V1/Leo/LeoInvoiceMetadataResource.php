<?php

namespace App\Http\Resources\Api\V1\Leo;

use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * rev7 LeoInvoiceMetadata (L12): same sources as the native OrderInvoiceResource, without download_url,
 * download, step-up hints or study name. The PDF is only reachable later through a secure link (L14).
 */
class LeoInvoiceMetadataResource extends JsonResource
{
    public function __construct(
        $resource,
        protected LaboratoryPurchase $order,
        protected ?Invoice $invoice = null,
        protected ?InvoiceRequest $invoiceRequest = null,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $issued = $this->invoice !== null;

        return [
            'id' => $issued ? $this->invoice->id : $this->invoiceRequest->id,
            'order_id' => $this->order->id,
            'uuid' => null,
            'status' => $issued ? 'issued' : 'pending',
            'issued_at' => $issued ? $this->invoice->created_at?->toIso8601String() : null,
            'total_cents' => (int) $this->order->total_cents,
        ];
    }
}
