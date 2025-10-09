<?php

namespace App\Actions\TaxProfiles;

use App\Jobs\DeleteJobFile;
use App\Models\TaxProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UpdateTaxProfileAction
{
    public function __invoke(
        string $name,
        string $rfc,
        string $zipcode,
        string $taxRegime,
        string $cfdiUse,
        TaxProfile $taxProfile,
        ?UploadedFile $fiscalCertificate = null,
    ): TaxProfile {
        DB::beginTransaction();

        $newFilePath = null;

        try {
            $previousFilePath = $taxProfile->fiscal_certificate;

            if ($fiscalCertificate) {
                $newFilePath = $fiscalCertificate->store('fiscal-certificates');
            }

            $taxProfile->update([
                'name' => $name,
                'rfc' => $rfc,
                'zipcode' => $zipcode,
                'tax_regime' => $taxRegime,
                'cfdi_use' => $cfdiUse,
                ...($newFilePath ? ['fiscal_certificate' => $newFilePath] : []),
            ]);

            DB::commit();

            if ($newFilePath) {
                dispatch(function () use ($previousFilePath) {
                    if (Storage::exists($previousFilePath)) {
                        Storage::delete($previousFilePath);
                    }
                })->afterResponse();
            }
            return $taxProfile;
        } catch (\Throwable $e) {
            DB::rollBack();
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
