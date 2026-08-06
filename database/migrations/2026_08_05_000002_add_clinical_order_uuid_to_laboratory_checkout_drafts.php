<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_checkout_drafts', function (Blueprint $table) {
            $table->uuid('clinical_order_uuid')->nullable()->after('promo_validation_token');
            $table->index('clinical_order_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_checkout_drafts', function (Blueprint $table) {
            $table->dropIndex(['clinical_order_uuid']);
            $table->dropColumn('clinical_order_uuid');
        });
    }
};
