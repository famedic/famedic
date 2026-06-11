<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LaboratoryTest */
class LaboratoryTestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('laboratoryTestCategory');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'brand' => $this->brand->value,
            'category' => $this->laboratoryTestCategory?->name,
            'price_cents' => $this->famedic_price_cents,
            'currency' => 'MXN',
            'requires_appointment' => $this->requires_appointment,
            'gda_id' => $this->gda_id,
            'available' => ! $this->trashed(),
        ];
    }
}
