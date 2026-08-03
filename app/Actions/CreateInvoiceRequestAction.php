<?php

namespace App\Actions;

use App\Models\InvoiceRequest;
use App\Models\TaxProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class CreateInvoiceRequestAction
{
    public function __invoke(
        Model $model,
        TaxProfile $taxProfile,
        ?string $cfdiUse = null,
    ): InvoiceRequest {
        $fiscalCertificatePath = null;
        $oldCertificate = null;

        try {
            $invoiceRequest = DB::transaction(function () use ($model, $taxProfile, $cfdiUse, &$fiscalCertificatePath, &$oldCertificate) {
                // Perfil activo reconsultado; no soft-deleted.
                $profile = TaxProfile::query()
                    ->whereKey($taxProfile->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if (! $profile) {
                    throw new InvalidArgumentException('El perfil fiscal no está activo.');
                }

                $purchaseCustomerId = $model->customer_id ?? null;
                if ($purchaseCustomerId === null || (int) $profile->customer_id !== (int) $purchaseCustomerId) {
                    throw new InvalidArgumentException('El perfil fiscal no pertenece al propietario de la compra.');
                }

                if (! filled($profile->fiscal_certificate) || ! Storage::exists($profile->fiscal_certificate)) {
                    throw new InvalidArgumentException('El perfil fiscal no tiene constancia disponible.');
                }

                $fiscalCertificatePath = 'invoice-requests/'.basename($profile->fiscal_certificate);
                Storage::copy($profile->fiscal_certificate, $fiscalCertificatePath);

                $existingInvoiceRequest = $model->invoiceRequest;

                // La misma selección del perfil origina snapshot + FK (sin re-buscar por atributos).
                $invoiceRequestData = [
                    'tax_profile_id' => $profile->id,
                    'name' => $profile->name,
                    'rfc' => $profile->rfc,
                    'zipcode' => $profile->zipcode,
                    'tax_regime' => $profile->tax_regime,
                    'cfdi_use' => $cfdiUse ?? $profile->cfdi_use,
                    'fiscal_certificate' => $fiscalCertificatePath,
                ];

                if ($existingInvoiceRequest) {
                    if ($existingInvoiceRequest->fiscal_certificate) {
                        $oldCertificate = $existingInvoiceRequest->fiscal_certificate;
                    }

                    $existingInvoiceRequest->update($invoiceRequestData);

                    return $existingInvoiceRequest->fresh();
                }

                return $model->invoiceRequest()->create($invoiceRequestData);
            });

            if ($oldCertificate && $oldCertificate !== $fiscalCertificatePath && Storage::exists($oldCertificate)) {
                Storage::delete($oldCertificate);
            }

            return $invoiceRequest;
        } catch (\Throwable $e) {
            if ($fiscalCertificatePath && Storage::exists($fiscalCertificatePath)) {
                Storage::delete($fiscalCertificatePath);
            }

            throw $e;
        }
    }
}
