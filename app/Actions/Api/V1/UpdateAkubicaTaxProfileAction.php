<?php

namespace App\Actions\Api\V1;

use App\Models\TaxProfile;
use Illuminate\Support\Str;

class UpdateAkubicaTaxProfileAction
{
    /**
     * @return array{tax_profile: TaxProfile}|array{error: string}
     */
    public function __invoke(
        TaxProfile $taxProfile,
        string $businessName,
        string $rfc,
        string $postalCode,
        string $taxRegime,
        ?string $cfdiUse = null,
    ): array {
        $normalizedRfc = Str::upper($rfc);

        if ($normalizedRfc !== $taxProfile->rfc) {
            $duplicate = $taxProfile->customer->taxProfiles()
                ->where('rfc', $normalizedRfc)
                ->where('id', '!=', $taxProfile->id)
                ->exists();

            if ($duplicate) {
                return ['error' => 'RFC_ALREADY_EXISTS'];
            }
        }

        $taxProfile->update([
            'name' => $businessName,
            'razon_social' => $businessName,
            'rfc' => $normalizedRfc,
            'zipcode' => $postalCode,
            'tax_regime' => $taxRegime,
            'cfdi_use' => $cfdiUse ?? $taxProfile->cfdi_use ?? 'G03',
        ]);

        return ['tax_profile' => $taxProfile->fresh()];
    }
}
