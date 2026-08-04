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
                $table->index('valid_from');
            }

            if (! Schema::hasColumn('coupons', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('valid_from');
                $table->index('expires_at');
            }

            if (! Schema::hasColumn('coupons', 'min_purchase_cents')) {
                $table->unsignedInteger('min_purchase_cents')->nullable()->after('expires_at');
                $table->index('min_purchase_cents');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (Schema::hasColumn('coupons', 'min_purchase_cents')) {
                $table->dropIndex(['min_purchase_cents']);
                $table->dropColumn('min_purchase_cents');
            }

            if (Schema::hasColumn('coupons', 'expires_at')) {
                $table->dropIndex(['expires_at']);
                $table->dropColumn('expires_at');
            }

            if (Schema::hasColumn('coupons', 'valid_from')) {
                $table->dropIndex(['valid_from']);
                $table->dropColumn('valid_from');
            }
        });
    }
};
