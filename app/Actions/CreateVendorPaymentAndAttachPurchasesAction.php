<?php

namespace App\Actions;

use App\Enums\VendorPaymentPurchaseType;
use App\Models\LaboratoryPurchase;
use App\Models\VendorPayment;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateVendorPaymentAndAttachPurchasesAction
{
    public function __invoke(Collection $purchases, string $paidAt, UploadedFile $proof): VendorPayment
    {
        DB::beginTransaction();

        try {
            $filePath = $proof->store('vendor_payments');

            $purchaseType = $purchases->first() instanceof LaboratoryPurchase
                ? VendorPaymentPurchaseType::LABORATORY
                : VendorPaymentPurchaseType::ONLINE_PHARMACY;

            $vendorPayment = VendorPayment::create([
                'paid_at' => Carbon::parse($paidAt),
                'proof_of_payment' => $filePath,
                'purchase_type' => $purchaseType,
            ]);

            foreach ($purchases as $purchase) {
                $purchase->vendorPayments()->attach($vendorPayment);
            }

            DB::commit();

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
