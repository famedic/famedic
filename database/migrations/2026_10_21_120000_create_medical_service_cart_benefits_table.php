<?php

use App\Models\Coupon;
use App\Models\MedicalServiceCart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medical_service_cart_benefits')) {
            return;
        }

        Schema::create('medical_service_cart_benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(MedicalServiceCart::class, 'cart_id')
                ->constrained('medical_service_carts')
                ->cascadeOnDelete();
            $table->foreignIdFor(Coupon::class)->constrained()->restrictOnDelete();
            $table->enum('benefit_type', ['coupon', 'credit']);
            $table->unsignedInteger('applied_cents')->default(0);
            $table->timestamps();

            // M2C permits at most one monetary benefit per medical cart.
            $table->unique('cart_id', 'medical_service_cart_benefits_cart_unique');
            $table->index(['coupon_id', 'benefit_type'], 'medical_service_cart_benefits_coupon_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_service_cart_benefits');
    }
};
