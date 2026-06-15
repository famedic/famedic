<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\Gender;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Contact */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => trim($this->full_name),
            'name' => $this->name,
            'paternal_lastname' => $this->paternal_lastname,
            'maternal_lastname' => $this->maternal_lastname,
            'phone' => $this->phone_for_display,
            'phone_country' => $this->phone_country,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'gender' => match ($this->gender) {
                Gender::MALE => 'male',
                Gender::FEMALE => 'female',
                default => null,
            },
        ];
    }
}
