<?php

namespace App\Actions;

use App\Models\VendorPayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UpdateVendorPaymentAction
{
    public function __invoke(
        VendorPayment $vendorPayment,
        array $purchaseIds,
        string $purchaseModel,
        string $paidAt,
        ?UploadedFile $proof = null
    ): VendorPayment {
        DB::beginTransaction();

        $filePath = null;

        try {
            $previousPath = $vendorPayment->proof_of_payment;
            $filePath = $proof ? $proof->store('vendor_payments') : $previousPath;

            $vendorPayment->update([
                'paid_at' => $paidAt,
                'proof_of_payment' => $filePath,
            ]);

            $relation = $vendorPayment->morphedByMany($purchaseModel, 'vendor_paymentable', 'vendor_paymentables');
            $relation->sync($purchaseIds);

            DB::commit();

            if ($proof && $previousPath && $previousPath !== $filePath) {
                dispatch(function () use ($previousPath) {
                    if (Storage::exists($previousPath)) {
                        Storage::delete($previousPath);
                    }
                })->afterResponse();
            }

            return $vendorPayment;
        } catch (\Throwable $e) {
            DB::rollBack();
            if (! empty($filePath) && Storage::exists($filePath)) {
                Storage::delete($filePath);
            }
            throw $e;
        }
    }
}
