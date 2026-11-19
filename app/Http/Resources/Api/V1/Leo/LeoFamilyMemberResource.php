<?php

namespace App\Http\Resources\Api\V1\Leo;

use App\Services\Api\V1\Leo\LeoOpaqueIdentifiers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * rev7 LeoFamilyMember (L08): opaque id, display name (first name), relationship and main-profile flag only.
 * Never birth_date, gender, last names or the internal id. Family accounts are dependents of the customer,
 * so they are never the main profile (same source as the native resource).
 */
class LeoFamilyMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => app(LeoOpaqueIdentifiers::class)->forSubject((int) $this->customer_id, LeoOpaqueIdentifiers::FAMILY_MEMBER, (int) $this->id),
            'display_name' => trim((string) $this->name),
            'relationship' => $this->kinship?->value,
            'is_main_profile' => false,
        ];
    }
}
