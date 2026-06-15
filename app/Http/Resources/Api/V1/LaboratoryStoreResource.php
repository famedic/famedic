<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LaboratoryStore */
class LaboratoryStoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand' => $this->brand->value,
            'name' => $this->name,
            'address' => $this->address,
            'state' => $this->state,
            'google_maps_url' => $this->google_maps_url,
            'weekly_hours' => $this->weekly_hours,
            'saturday_hours' => $this->saturday_hours,
            'sunday_hours' => $this->sunday_hours,
            'is_active' => ! $this->trashed(),
        ];
    }
}
