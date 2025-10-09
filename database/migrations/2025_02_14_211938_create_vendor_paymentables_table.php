<?php

use App\Models\VendorPayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_paymentables', function (Blueprint $table) {
            $table->foreignIdFor(VendorPayment::class)->constrained();
            $table->morphs('vendor_paymentable', "vendor_paymentable_index");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payments');
    }
};
