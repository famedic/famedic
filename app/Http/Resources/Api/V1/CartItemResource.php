<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LaboratoryCartItem */
class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('laboratoryTest');

        return [
            'id' => $this->id,
            'laboratory_test_id' => $this->laboratory_test_id,
            'name' => $this->laboratoryTest->name,
            'price_cents' => $this->laboratoryTest->famedic_price_cents,
            'currency' => 'MXN',
            'quantity' => 1,
            'requires_appointment' => $this->laboratoryTest->requires_appointment,
        ];
    }
}
