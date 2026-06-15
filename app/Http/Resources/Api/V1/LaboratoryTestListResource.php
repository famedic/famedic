<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LaboratoryTest */
class LaboratoryTestListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('laboratoryTestCategory');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand' => $this->brand->value,
            'price_cents' => (int) $this->famedic_price_cents,
            'currency' => 'MXN',
            'requires_appointment' => (bool) $this->requires_appointment,
            'category' => $this->laboratoryTestCategory ? [
                'id' => $this->laboratoryTestCategory->id,
                'name' => $this->laboratoryTestCategory->name,
            ] : null,
            'indications' => $this->indications,
            'is_available' => ! $this->trashed(),
        ];
    }
}
