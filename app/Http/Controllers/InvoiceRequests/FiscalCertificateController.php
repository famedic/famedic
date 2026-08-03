<?php

namespace App\Http\Controllers\InvoiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceRequests\ShowInvoiceRequestRequest;
use App\Models\InvoiceRequest;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class FiscalCertificateController extends Controller
{
    public function __invoke(ShowInvoiceRequestRequest $request, InvoiceRequest $invoiceRequest)
    {
        abort_unless(filled($invoiceRequest->fiscal_certificate), 404);
        abort_unless(Storage::exists($invoiceRequest->fiscal_certificate), 404);

        return Inertia::location(
            Storage::temporaryUrl(
                $invoiceRequest->fiscal_certificate,
                now()->addMinutes(5)
            )
        );
    }
}
