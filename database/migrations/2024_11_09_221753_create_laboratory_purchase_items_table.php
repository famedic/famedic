<?php

use App\Models\LaboratoryPurchase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->string('gda_id');
            $table->string('name');
            $table->mediumText('indications');
            $table->unsignedInteger('price_cents');
            $table->foreignIdFor(LaboratoryPurchase::class)->constrained();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_purchase_items');
    }
};
