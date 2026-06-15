<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutPaymentLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'],
            'expires_at' => $this->resource['expires_at'],
            'expires_in_seconds' => $this->resource['expires_in_seconds'],
            'brand' => $this->resource['brand'],
            'is_ready' => $this->resource['is_ready'],
        ];
    }
}
