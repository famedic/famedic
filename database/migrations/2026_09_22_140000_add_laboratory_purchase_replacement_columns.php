<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_purchases', 'replaces_laboratory_purchase_id')) {
                $table->unsignedBigInteger('replaces_laboratory_purchase_id')->nullable()->after('cart_id');
                $table->foreign('replaces_laboratory_purchase_id', 'lab_purchases_replaces_fk')
                    ->references('id')
                    ->on('laboratory_purchases')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('laboratory_purchases', 'replacement_laboratory_purchase_id')) {
                $table->unsignedBigInteger('replacement_laboratory_purchase_id')->nullable()->after('replaces_laboratory_purchase_id');
                $table->foreign('replacement_laboratory_purchase_id', 'lab_purchases_replacement_fk')
                    ->references('id')
                    ->on('laboratory_purchases')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            if (Schema::hasColumn('laboratory_purchases', 'replacement_laboratory_purchase_id')) {
                $table->dropForeign('lab_purchases_replacement_fk');
                $table->dropColumn('replacement_laboratory_purchase_id');
            }

            if (Schema::hasColumn('laboratory_purchases', 'replaces_laboratory_purchase_id')) {
                $table->dropForeign('lab_purchases_replaces_fk');
                $table->dropColumn('replaces_laboratory_purchase_id');
            }
        });
    }
};
