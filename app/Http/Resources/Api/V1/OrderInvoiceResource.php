<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Support\Api\V1\LaboratoryOrderStatus;
use App\Support\Api\V1\OrderDocumentDownloadSupport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderInvoiceResource extends JsonResource
{
    public function __construct(
        $resource,
        protected LaboratoryPurchase $order,
        protected ?Invoice $invoice = null,
        protected ?InvoiceRequest $invoiceRequest = null,
        protected bool $includeOrderStudyName = true,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $issued = $this->invoice !== null;

        $data = [
            'id' => $issued ? $this->invoice->id : $this->invoiceRequest->id,
            'order_id' => $this->order->id,
            'uuid' => null,
            'status' => $issued ? 'issued' : 'pending',
            'issued_at' => $issued ? $this->invoice->created_at?->toIso8601String() : null,
            'download_url' => $issued ? route('invoice', ['invoice' => $this->invoice->id]) : null,
            'total_cents' => (int) $this->order->total_cents,
        ];

        if ($issued) {
            $data['download'] = [
                'type' => 'bearer',
                'url' => app(OrderDocumentDownloadSupport::class)
                    ->invoiceBearerDownloadUrl($this->order, $this->invoice),
            ];
        }

        if ($this->includeOrderStudyName) {
            $data['order_study_name'] = LaboratoryOrderStatus::formatStudyName($this->order);
        }

        return $data;
    }
}
