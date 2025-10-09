<?php

namespace App\Http\Controllers;

use App\Models\VendorPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class VendorPaymentController extends Controller
{
    public function __invoke(Request $request, VendorPayment $vendorPayment)
    {
        return Inertia::location(
            Storage::temporaryUrl(
                $vendorPayment->proof_of_payment,
                now()->addMinutes(5)
            )
        );
    }
}
