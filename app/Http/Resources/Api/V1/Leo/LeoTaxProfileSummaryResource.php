<?php

namespace App\Http\Resources\Api\V1\Leo;

use App\Models\TaxProfile;
use App\Services\Api\V1\Leo\LeoOpaqueIdentifiers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * rev7 LeoTaxProfileSummary (D-1, L13b): opaque id, business name, RFC masked to its last 3 characters,
 * tax regime, CFDI use and default flag. Never the full RFC, postal code, fiscal address, email or CSF.
 */
class LeoTaxProfileSummaryResource extends JsonResource
{
    private const RFC = '/\A[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}\z/u';

    /**
     * A profile is listed only when every required field has a real value: no placeholder is invented
     * for an incomplete or non-RFC record (it could not be used to request an invoice either).
     */
    public static function presentable(TaxProfile $profile): bool
    {
        return preg_match(self::RFC, self::normalizedRfc($profile)) === 1
            && trim((string) ($profile->razon_social ?: $profile->name)) !== ''
            && trim((string) $profile->tax_regime) !== ''
            && trim((string) $profile->cfdi_use) !== '';
    }

    public function toArray(Request $request): array
    {
        $rfc = self::normalizedRfc($this->resource);

        return [
            'id' => app(LeoOpaqueIdentifiers::class)->forSubject((int) $this->customer_id, LeoOpaqueIdentifiers::TAX_PROFILE, (int) $this->id),
            'business_name' => trim((string) ($this->razon_social ?: $this->name)),
            'rfc_masked' => str_repeat('*', mb_strlen($rfc) - 3).mb_substr($rfc, -3),
            'tax_regime' => (string) $this->tax_regime,
            'cfdi_use' => (string) $this->cfdi_use,
            'is_default' => (bool) $this->is_default,
        ];
    }

    private static function normalizedRfc(TaxProfile $profile): string
    {
        return mb_strtoupper(trim((string) $profile->rfc));
    }
}
