<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_checkout_drafts', function (Blueprint $table) {
            $table->string('promo_validation_token', 64)->nullable()->after('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_checkout_drafts', function (Blueprint $table) {
            $table->dropColumn('promo_validation_token');
        });
    }
};
