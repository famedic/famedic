<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medical_service_cart_items')) {
            return;
        }

        Schema::create('medical_service_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')
                ->constrained('medical_service_carts')
                ->cascadeOnDelete();
            $table->enum('item_type', ['membership'])->default('membership');
            $table->enum('membership_plan_code', ['basic_annual']);
            $table->string('name', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('subtotal_cents');
            $table->boolean('requires_appointment')->default(false);
            $table->timestamps();

            $table->unique(
                ['cart_id', 'membership_plan_code'],
                'medical_service_cart_items_cart_plan_unique'
            );
            $table->index(['cart_id', 'item_type'], 'medical_service_cart_items_cart_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_service_cart_items');
    }
};
