<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\Gender;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FamilyAccount */
class FamilyMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => trim($this->full_name),
            'relationship' => $this->kinship?->value,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'gender' => match ($this->gender) {
                Gender::MALE => 'male',
                Gender::FEMALE => 'female',
                default => null,
            },
            'is_main_profile' => false,
        ];
    }
}
