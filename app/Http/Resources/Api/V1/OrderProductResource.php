<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LaboratoryPurchaseItem */
class OrderProductResource extends JsonResource
{
    public function __construct(
        $resource,
        protected LaboratoryPurchase $order,
        protected ?LaboratoryTest $laboratoryTest = null,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'laboratory_test_id' => $this->laboratoryTest?->id,
            'name' => $this->name,
            'brand' => $this->order->brand?->value ?? $this->order->brand,
            'price_cents' => (int) $this->price_cents,
            'currency' => 'MXN',
            'quantity' => 1,
            'requires_appointment' => (bool) ($this->laboratoryTest?->requires_appointment ?? false),
        ];
    }
}
