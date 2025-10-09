<?php

namespace App\Http\Controllers;

use App\Http\Requests\Invoices\ShowInvoiceRequest;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    public function __invoke(ShowInvoiceRequest $request, Invoice $invoice)
    {
        return Inertia::location(
            Storage::temporaryUrl(
                $invoice->invoice,
                now()->addMinutes(5)
            )
        );
    }
}
