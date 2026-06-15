<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\LaboratoryBrand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LaboratoryBrand */
class LaboratoryBrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LaboratoryBrand $brand */
        $brand = $this->resource;

        return [
            'id' => $brand->value,
            'name' => $brand->label(),
            'label' => $brand->label(),
            'is_active' => true,
        ];
    }
}
