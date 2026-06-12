<?php

namespace App\Actions\Api\V1;

use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\TaxProfile;
use App\Support\Api\V1\LaboratoryInvoiceSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateAkubicaInvoiceRequestAction
{
    public function __construct(
        private readonly LaboratoryInvoiceSupport $invoiceSupport,
    ) {}

    /**
     * @return array{invoice_request: InvoiceRequest, tax_profile_id: int}|array{error: string}
     */
    public function __invoke(
        LaboratoryPurchase $order,
        TaxProfile $taxProfile,
        ?string $cfdiUse = null,
    ): array {
        $order->loadMissing(['invoice', 'invoiceRequest']);

        if ($order->trashed()) {
            return ['error' => 'ORDER_NOT_INVOICEABLE'];
        }

        if ($order->invoice) {
            return ['error' => 'INVOICE_ALREADY_EXISTS'];
        }

        if ($order->invoiceRequest) {
            return ['error' => 'INVOICE_REQUEST_ALREADY_EXISTS'];
        }

        if (! $this->invoiceSupport->canRequestInvoice($order)) {
            return ['error' => 'ORDER_NOT_INVOICEABLE'];
        }

        if ($cfdiUse !== null && $taxProfile->cfdi_use !== $cfdiUse) {
            $taxProfile->update(['cfdi_use' => $cfdiUse]);
            $taxProfile->refresh();
        }

        $invoiceRequest = $this->persistInvoiceRequest($order, $taxProfile);

        return [
            'invoice_request' => $invoiceRequest,
            'tax_profile_id' => $taxProfile->id,
        ];
    }

    private function persistInvoiceRequest(
        LaboratoryPurchase $order,
        TaxProfile $taxProfile,
    ): InvoiceRequest {
        DB::beginTransaction();

        $fiscalCertificatePath = null;

        try {
            if ($taxProfile->fiscal_certificate) {
                $fiscalCertificatePath = 'invoice-requests/'.basename($taxProfile->fiscal_certificate);

                if (Storage::exists($taxProfile->fiscal_certificate)) {
                    Storage::copy($taxProfile->fiscal_certificate, $fiscalCertificatePath);
                }
            }

            $invoiceRequest = $order->invoiceRequest()->create([
                'name' => $taxProfile->name,
                'rfc' => $taxProfile->rfc,
                'zipcode' => $taxProfile->zipcode,
                'tax_regime' => $taxProfile->tax_regime,
                'cfdi_use' => $taxProfile->cfdi_use,
                'fiscal_certificate' => $fiscalCertificatePath,
            ]);

            DB::commit();

            return $invoiceRequest;
        } catch (\Throwable $e) {
            DB::rollBack();

            if ($fiscalCertificatePath && Storage::exists($fiscalCertificatePath)) {
                Storage::delete($fiscalCertificatePath);
            }

            throw $e;
        }
    }
}
