<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('laboratory_cart_memberships')) {
            return;
        }

        Schema::create('laboratory_cart_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('laboratory_brand');
            $table->timestamps();

            $table->unique(['customer_id', 'laboratory_brand']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_cart_memberships');
    }
};
