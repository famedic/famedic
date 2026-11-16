<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LaboratoryTest */
class LaboratoryTestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['laboratoryTestCategory', 'packageDefinition']);

        return [
            'id' => $this->id,
            // RC2 B01/B02 (D8): additive discriminator; 'package' only for explicitly classified packages.
            'item_type' => $this->packageDefinition ? 'package' : 'study',
            'name' => $this->name,
            'description' => $this->description,
            'brand' => $this->brand->value,
            'category' => $this->laboratoryTestCategory?->name,
            'price_cents' => (int) $this->famedic_price_cents,
            'currency' => 'MXN',
            'requires_appointment' => $this->requires_appointment,
            'gda_id' => $this->gda_id,
            'available' => ! $this->trashed(),
            'pricing' => (new LaboratoryPricingResource($this->resource))->resolve($request),
        ];
    }
}
