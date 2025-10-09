<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_pharmacy_cart_items', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('quantity')->default(1);
            $table->integer('vitau_product_id');
            $table->foreignIdFor(Customer::class)->constrained();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_pharmacy_cart_items');
    }
};
