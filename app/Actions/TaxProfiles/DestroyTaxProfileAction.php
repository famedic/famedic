<?php

namespace App\Actions\TaxProfiles;

use App\Models\TaxProfile;
use Illuminate\Support\Facades\Storage;

class DestroyTaxProfileAction
{
    public function __invoke(
        TaxProfile $taxProfile
    ): void {

        $fiscalCertificate = $taxProfile->fiscal_certificate;

        $taxProfile->delete();

        dispatch(function () use ($fiscalCertificate) {
            if (Storage::exists($fiscalCertificate)) {
                Storage::delete($fiscalCertificate);
            }
        })->afterResponse();
    }
}
