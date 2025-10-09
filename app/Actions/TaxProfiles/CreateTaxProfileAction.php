<?php

namespace App\Actions\TaxProfiles;

use App\Models\TaxProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CreateTaxProfileAction
{
    public function __invoke(
        string $name,
        string $rfc,
        string $zipcode,
        string $taxRegime,
        string $cfdiUse,
        UploadedFile $fiscalCertificate,
    ): TaxProfile {
        $newFilePath = $fiscalCertificate->store('fiscal-certificates');

        try {
            return Auth::user()->customer->taxProfiles()->create([
                'name' => $name,
                'rfc' => $rfc,
                'zipcode' => $zipcode,
                'tax_regime' => $taxRegime,
                'cfdi_use' => $cfdiUse,
                'fiscal_certificate' => $newFilePath,
            ]);
        } catch (\Throwable $e) {
            if ($newFilePath) {
                dispatch(function () use ($newFilePath) {
                    if (Storage::exists($newFilePath)) {
                        Storage::delete($newFilePath);
                    }
                })->afterResponse();
            }
            throw $e;
        }
    }
}
