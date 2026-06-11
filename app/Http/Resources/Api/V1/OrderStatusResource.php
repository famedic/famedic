<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Api\V1\LaboratoryOrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LaboratoryPurchase */
class OrderStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = LaboratoryOrderStatus::resolve($this->resource);

        return [
            'order_id' => $this->id,
            'status' => $status,
            'status_label' => LaboratoryOrderStatus::label($status),
            'pipeline' => null,
            'is_cancelled' => $this->trashed(),
            'results_available' => LaboratoryOrderStatus::hasResults($this->resource),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
