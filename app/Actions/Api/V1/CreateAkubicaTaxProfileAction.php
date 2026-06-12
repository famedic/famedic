<?php

namespace App\Actions\Api\V1;

use App\Models\Customer;
use App\Models\TaxProfile;
use Illuminate\Support\Str;

class CreateAkubicaTaxProfileAction
{
    public function __invoke(
        Customer $customer,
        string $businessName,
        string $rfc,
        string $postalCode,
        string $taxRegime,
        ?string $cfdiUse = null,
    ): TaxProfile {
        return $customer->taxProfiles()->create([
            'name' => $businessName,
            'razon_social' => $businessName,
            'rfc' => Str::upper($rfc),
            'zipcode' => $postalCode,
            'codigo_postal_original' => $postalCode,
            'tax_regime' => $taxRegime,
            'cfdi_use' => $cfdiUse ?? 'G03',
            'tipo_persona' => $this->resolvePersonType($rfc),
            'estatus_sat' => 'Desconocido',
            'tipo_persona_detectado_por' => 'akubica_api',
        ]);
    }

    private function resolvePersonType(string $rfc): string
    {
        $rfc = Str::upper(trim($rfc));

        if (strlen($rfc) === 12) {
            return 'moral';
        }

        if (strlen($rfc) === 13) {
            return 'fisica';
        }

        return preg_match('/^[A-Z&Ñ]{3}\d{6}/', $rfc) ? 'moral' : 'fisica';
    }
}
