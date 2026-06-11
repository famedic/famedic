<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\LaboratoryBrand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class CartResource extends JsonResource
{
    protected LaboratoryBrand $brand;

    /**
     * @param  Collection<int, \App\Models\LaboratoryCartItem>  $items
     */
    public static function forBrand(LaboratoryBrand $brand, Collection $items): self
    {
        $resource = new self($items);
        $resource->brand = $brand;

        return $resource;
    }

    public function toArray(Request $request): array
    {
        /** @var Collection<int, \App\Models\LaboratoryCartItem> $items */
        $items = $this->resource;
        $items->loadMissing('laboratoryTest');

        $subtotalCents = (int) $items->sum(fn ($item) => $item->laboratoryTest->public_price_cents);
        $totalCents = (int) $items->sum(fn ($item) => $item->laboratoryTest->famedic_price_cents);

        return [
            'brand' => $this->brand->value,
            'items' => CartItemResource::collection($items),
            'subtotal_cents' => $subtotalCents,
            'discount_cents' => $subtotalCents - $totalCents,
            'total_cents' => $totalCents,
        ];
    }
}
