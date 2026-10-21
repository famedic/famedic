<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MedicalServiceCartItem */
class MedicalServiceCartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_type' => $this->item_type,
            'name' => $this->name,
            'membership_plan_code' => $this->membership_plan_code,
            'laboratory_test_id' => null,
            'brand' => null,
            'quantity' => $this->quantity,
            'unit_price_cents' => $this->unit_price_cents,
            'subtotal_cents' => $this->subtotal_cents,
            'requires_appointment' => $this->requires_appointment,
        ];
    }
}
