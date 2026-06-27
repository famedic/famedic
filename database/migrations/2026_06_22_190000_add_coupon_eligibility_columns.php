<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'valid_from')) {
                $table->timestamp('valid_from')->nullable()->after('remaining_cents');
            }
            if (! Schema::hasColumn('coupons', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('valid_from');
            }
            if (! Schema::hasColumn('coupons', 'min_purchase_cents')) {
                $table->unsignedInteger('min_purchase_cents')->nullable()->after('expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $columns = ['valid_from', 'expires_at', 'min_purchase_cents'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('coupons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
