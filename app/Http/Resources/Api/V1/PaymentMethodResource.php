<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EfevooToken */
class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expiration = (string) ($this->card_expiration ?? '');

        return [
            'id' => $this->id,
            'brand' => strtolower((string) $this->card_brand),
            'last4' => $this->card_last_four,
            'expiration_month' => strlen($expiration) >= 2 ? substr($expiration, 0, 2) : null,
            'expiration_year' => strlen($expiration) === 4 ? '20'.substr($expiration, 2, 2) : null,
            'holder_name' => $this->card_holder,
            'type' => 'card',
        ];
    }
}
