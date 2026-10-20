<?php

namespace App\Http\Controllers\InvoiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceRequests\ShowInvoiceRequestRequest;
use App\Models\InvoiceRequest;
use App\Support\TaxProfiles\FiscalCertificateStorage;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class FiscalCertificateController extends Controller
{
    public function __invoke(ShowInvoiceRequestRequest $request, InvoiceRequest $invoiceRequest)
    {
        $path = $this->resolveCertificatePath($invoiceRequest);
        abort_unless($path !== null, 404);

        $disk = FiscalCertificateStorage::resolveDisk($path);
        abort_unless($disk !== null, 404);

        return Inertia::location(
            Storage::disk($disk)->temporaryUrl(
                $path,
                now()->addMinutes(5)
            )
        );
    }

    private function resolveCertificatePath(InvoiceRequest $invoiceRequest): ?string
    {
        if (filled($invoiceRequest->fiscal_certificate)
            && FiscalCertificateStorage::exists($invoiceRequest->fiscal_certificate)) {
            return $invoiceRequest->fiscal_certificate;
        }

        $taxProfile = $invoiceRequest->taxProfile;
        if (filled($taxProfile?->fiscal_certificate)
            && FiscalCertificateStorage::exists($taxProfile->fiscal_certificate)) {
            return $taxProfile->fiscal_certificate;
        }

        return null;
    }
}
