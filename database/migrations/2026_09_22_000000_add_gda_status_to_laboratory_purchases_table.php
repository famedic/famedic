<?php

use App\Enums\GdaOrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('laboratory_purchases', 'gda_status')) {
                $table->string('gda_status', 32)
                    ->nullable()
                    ->after('gda_warning_message')
                    ->index('laboratory_purchases_gda_status_idx');
            }
        });

        DB::table('laboratory_purchases')
            ->whereNotNull('gda_consecutivo')
            ->whereRaw("TRIM(CAST(gda_consecutivo AS CHAR)) <> ''")
            ->whereRaw("TRIM(COALESCE(gda_order_id, '')) <> ''")
            ->whereRaw("TRIM(COALESCE(gda_order_id, '')) <> '0'")
            ->update(['gda_status' => GdaOrderStatus::Confirmed->value]);
    }

    public function down(): void
    {
        Schema::table('laboratory_purchases', function (Blueprint $table) {
            if (Schema::hasColumn('laboratory_purchases', 'gda_status')) {
                $table->dropIndex('laboratory_purchases_gda_status_idx');
                $table->dropColumn('gda_status');
            }
        });
    }
};
