<?php

namespace App\Actions;

use App\Models\VendorPayment;
use Illuminate\Support\Facades\Storage;

class DeleteVendorPaymentAction
{
    public function __invoke(VendorPayment $vendorPayment): void
    {
        $filePath = $vendorPayment->proof_of_payment;

        $vendorPayment->delete();

        if (Storage::exists($filePath)) {
            dispatch(function () use ($filePath) {
                Storage::delete($filePath);
            })->afterResponse();
        }
    }
}
