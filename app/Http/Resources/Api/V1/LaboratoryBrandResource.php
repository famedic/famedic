<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\LaboratoryBrand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LaboratoryBrand */
class LaboratoryBrandResource extends JsonResource
{
    /**
     * @param  array{available_states: array<int, string>, stores_count: int}  $coverage
     */
    public function __construct(
        $resource,
        protected array $coverage = ['available_states' => [], 'stores_count' => 0],
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var LaboratoryBrand $brand */
        $brand = $this->resource;

        return [
            'id' => $brand->value,
            'name' => $brand->label(),
            'label' => $brand->label(),
            'is_active' => true,
            'available_states' => $this->coverage['available_states'],
            'stores_count' => $this->coverage['stores_count'],
        ];
    }
}
