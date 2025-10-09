<?php

namespace App\Actions;

use App\Models\InvoiceRequest;
use App\Models\TaxProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateInvoiceRequestAction
{
    public function __invoke(
        Model $model,
        TaxProfile $taxProfile,
    ): InvoiceRequest {
        DB::beginTransaction();

        $fiscalCertificatePath = null;
        $oldCertificate = null;

        try {
            $fiscalCertificatePath = 'invoice-requests/' . basename($taxProfile->fiscal_certificate);
            Storage::copy($taxProfile->fiscal_certificate, $fiscalCertificatePath);

            $existingInvoiceRequest = $model->invoiceRequest;

            $invoiceRequestData = [
                'name' => $taxProfile->name,
                'rfc' => $taxProfile->rfc,
                'zipcode' => $taxProfile->zipcode,
                'tax_regime' => $taxProfile->tax_regime,
                'cfdi_use' => $taxProfile->cfdi_use,
                'fiscal_certificate' => $fiscalCertificatePath,
            ];

            if ($existingInvoiceRequest) {
                if ($existingInvoiceRequest->fiscal_certificate) {
                    $oldCertificate = $existingInvoiceRequest->fiscal_certificate;
                }

                $existingInvoiceRequest->update($invoiceRequestData);

                $invoiceRequest = $existingInvoiceRequest;
            } else {
                $invoiceRequest = $model->invoiceRequest()->create($invoiceRequestData);
            }

            DB::commit();

            if ($oldCertificate) {
                dispatch(function () use ($oldCertificate) {
                    if (Storage::exists($oldCertificate)) {
                        Storage::delete($oldCertificate);
                    }
                })->afterResponse();
            }

            return $invoiceRequest;
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($fiscalCertificatePath) {
                dispatch(function () use ($fiscalCertificatePath) {
                    if (Storage::exists($fiscalCertificatePath)) {
                        Storage::delete($fiscalCertificatePath);
                    }
                })->afterResponse();
            }
            throw $e;
        }
    }
}
