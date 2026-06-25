<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('coupon_approval_requests', 'rejection_reason')) {
            Schema::table('coupon_approval_requests', function (Blueprint $table) {
                $table->text('rejection_reason')->nullable()->after('rejected_at');
            });
        }

        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'rejected_reason')) {
                $table->text('rejected_reason')->nullable()->after('authorized_by_user_id');
            }
            if (! Schema::hasColumn('coupons', 'rejected_by_user_id')) {
                $table->foreignId('rejected_by_user_id')->nullable()->after('rejected_reason')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('coupons', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (Schema::hasColumn('coupons', 'rejected_by_user_id')) {
                $table->dropForeign(['rejected_by_user_id']);
            }
            $columns = array_filter([
                Schema::hasColumn('coupons', 'rejected_reason') ? 'rejected_reason' : null,
                Schema::hasColumn('coupons', 'rejected_by_user_id') ? 'rejected_by_user_id' : null,
                Schema::hasColumn('coupons', 'rejected_at') ? 'rejected_at' : null,
            ]);
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        if (Schema::hasColumn('coupon_approval_requests', 'rejection_reason')) {
            Schema::table('coupon_approval_requests', function (Blueprint $table) {
                $table->dropColumn('rejection_reason');
            });
        }
    }
};
