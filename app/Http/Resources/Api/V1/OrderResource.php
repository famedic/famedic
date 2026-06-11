<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Api\V1\LaboratoryOrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LaboratoryPurchase */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = LaboratoryOrderStatus::resolve($this->resource);

        return [
            'id' => $this->id,
            'status' => $status,
            'status_label' => LaboratoryOrderStatus::label($status),
            'brand' => $this->brand?->value ?? $this->brand,
            'study_name' => LaboratoryOrderStatus::formatStudyName($this->resource),
            'total_cents' => (int) $this->total_cents,
            'currency' => 'MXN',
            'is_cancelled' => $this->trashed(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
