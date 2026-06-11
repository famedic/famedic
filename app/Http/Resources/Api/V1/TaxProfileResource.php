<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TaxProfile */
class TaxProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rfc' => $this->rfc,
            'business_name' => $this->razon_social ?: $this->name,
            'tax_regime' => $this->tax_regime,
            'cfdi_use' => $this->cfdi_use,
            'postal_code' => $this->zipcode,
        ];
    }
}
