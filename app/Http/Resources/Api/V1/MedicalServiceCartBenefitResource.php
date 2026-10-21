<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MedicalServiceCartBenefit */
class MedicalServiceCartBenefitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->benefit_type,
            'coupon_id' => $this->coupon_id,
            'code' => $this->coupon?->code,
            'description' => $this->coupon?->description ?: ($this->benefit_type === 'credit'
                ? 'Saldo a favor'
                : 'Cupón'),
            'applied_cents' => (int) $this->applied_cents,
            'currency' => 'MXN',
        ];
    }
}
