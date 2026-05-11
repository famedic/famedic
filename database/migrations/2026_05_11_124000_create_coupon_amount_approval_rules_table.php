<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coupon_amount_approval_rules')) {
            return;
        }

        Schema::create('coupon_amount_approval_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('min_amount_cents');
            $table->unsignedInteger('max_amount_cents')->nullable();
            $table->unsignedInteger('required_approvals')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_amount_approval_rules');
    }
};

