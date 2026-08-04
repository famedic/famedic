<?php

namespace App\Http\Controllers;

use App\Http\Requests\Invoices\ShowInvoiceRequest;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class InvoiceXmlController extends Controller
{
    public function __invoke(ShowInvoiceRequest $request, Invoice $invoice)
    {
        abort_unless(filled($invoice->invoice_xml), 404);

        return Inertia::location(
            Storage::temporaryUrl(
                $invoice->invoice_xml,
                now()->addMinutes(5)
            )
        );
    }
}
