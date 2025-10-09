<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_pharmacy_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('vitau_order_id');
            $table->string('name');
            $table->string('paternal_lastname');
            $table->string('maternal_lastname');
            $table->string('phone');
            $table->string('phone_country');
            $table->string('street');
            $table->string('number');
            $table->string('neighborhood');
            $table->string('state');
            $table->string('city');
            $table->string('zipcode');
            $table->string('additional_references')->nullable();
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('shipping_price_cents');
            $table->unsignedInteger('tax_cents');
            $table->unsignedInteger('discount_cents');
            $table->unsignedInteger('total_cents');
            $table->date('expected_delivery_date');
            $table->foreignIdFor(Customer::class)->constrained();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_pharmacy_purchases');
    }
};
