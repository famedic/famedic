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
        return Inertia::location(
            Storage::temporaryUrl(
                $invoiceRequest->fiscal_certificate,
                now()->addMinutes(5)
            )
        );
    }
}
