<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Never claim ownership of an imported column or silently reinterpret its values.
        if (Schema::hasColumn('laboratory_purchases', 'gda_status')) {
            throw new RuntimeException('GDA status column already exists; review imported schema and values before migration.');
        }
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            $table->string('gda_status', 32)->nullable()->index('laboratory_purchases_gda_status_idx');
        });
        // No historical backfill. The model supplies pending only for new purchases.
    }

    public function down(): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            $table->dropIndex('laboratory_purchases_gda_status_idx');
            $table->dropColumn('gda_status');
        });
    }
};
