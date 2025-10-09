<?php

use App\Models\OnlinePharmacyPurchase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_pharmacy_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->string('vitau_product_id');
            $table->string('name');
            $table->string('presentation')->nullable();
            $table->tinyInteger('quantity')->default(1);
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('tax_cents');
            $table->unsignedInteger('discount_cents');
            $table->unsignedInteger('total_cents');
            $table->foreignIdFor(OnlinePharmacyPurchase::class)->constrained()->name('online_pharmacy_purchase_purchase_item_id');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_pharmacy_purchase_items');
    }
};
