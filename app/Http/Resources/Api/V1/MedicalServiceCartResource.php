<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MedicalServiceCart */
class MedicalServiceCartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->resource->relationLoaded('items')
            ? $this->items
            : $this->items()->get();

        return [
            'cart_id' => $this->id,
            'service_line' => $this->service_line,
            'items' => MedicalServiceCartItemResource::collection($items),
            'totals' => [
                'subtotal_cents' => (int) $this->subtotal_cents,
                'discount_cents' => (int) $this->discount_cents,
                'credit_applied_cents' => (int) $this->credit_applied_cents,
                'total_cents' => (int) $this->total_cents,
                'currency' => 'MXN',
                'item_count' => $items->count(),
            ],
            'applied_benefits' => MedicalServiceCartBenefitResource::collection(
                $this->resource->relationLoaded('benefits')
                    ? $this->benefits->filter(fn ($benefit) => (int) $benefit->applied_cents > 0)->values()
                    : $this->benefits()->with('coupon')->get()->filter(fn ($benefit) => (int) $benefit->applied_cents > 0)->values(),
            ),
            'requires_appointment' => $items->contains(fn ($item) => (bool) $item->requires_appointment),
        ];
    }
}
