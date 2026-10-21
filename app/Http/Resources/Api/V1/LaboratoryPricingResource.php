<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LaboratoryTest;
use App\Services\Api\V1\LaboratoryPricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LaboratoryTest */
class LaboratoryPricingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return app(LaboratoryPricingService::class)->for($this->resource);
    }
}
