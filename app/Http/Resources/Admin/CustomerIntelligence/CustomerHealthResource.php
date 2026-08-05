<?php

namespace App\Http\Resources\Admin\CustomerIntelligence;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array<string, mixed> */
class CustomerHealthResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return is_array($this->resource)
            ? $this->resource
            : (array) $this->resource;
    }
}
