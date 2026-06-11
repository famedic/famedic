<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Support\Api\V1\LaboratoryOrderResults;
use App\Support\Api\V1\LaboratoryOrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LaboratoryPurchase */
class OrderResultsListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = LaboratoryOrderStatus::resolve($this->resource);

        return [
            'order_id' => $this->id,
            'study_name' => LaboratoryOrderStatus::formatStudyName($this->resource),
            'brand' => $this->brand?->value ?? $this->brand,
            'status' => $status,
            'results_available' => true,
            'available_at' => LaboratoryOrderResults::availableAt($this->resource)?->toIso8601String(),
            'has_pdf' => LaboratoryOrderResults::hasPdf($this->resource),
        ];
    }
}
