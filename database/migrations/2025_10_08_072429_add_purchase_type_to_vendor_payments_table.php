<?php

use App\Enums\VendorPaymentPurchaseType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_payments', function (Blueprint $table) {
            $table->string('purchase_type')->nullable()->after('id');
        });

        DB::table('vendor_payments')->chunkById(100, function ($vendorPayments) {
            foreach ($vendorPayments as $vendorPayment) {
                $hasLaboratoryPurchases = DB::table('vendor_paymentables')
                    ->where('vendor_payment_id', $vendorPayment->id)
                    ->where('vendor_paymentable_type', 'App\\Models\\LaboratoryPurchase')
                    ->exists();

                $purchaseType = $hasLaboratoryPurchases
                    ? VendorPaymentPurchaseType::LABORATORY->value
                    : VendorPaymentPurchaseType::ONLINE_PHARMACY->value;

                DB::table('vendor_payments')
                    ->where('id', $vendorPayment->id)
                    ->update(['purchase_type' => $purchaseType]);
            }
        });

        Schema::table('vendor_payments', function (Blueprint $table) {
            $table->string('purchase_type')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_payments', function (Blueprint $table) {
            $table->dropColumn('purchase_type');
        });
    }
};
