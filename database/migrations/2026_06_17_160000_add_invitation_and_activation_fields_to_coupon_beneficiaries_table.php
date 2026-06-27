<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coupon_beneficiaries')) {
            return;
        }

        Schema::table('coupon_beneficiaries', function (Blueprint $table) {
            if (! Schema::hasColumn('coupon_beneficiaries', 'invitation_sent_at')) {
                $table->timestamp('invitation_sent_at')->nullable()->after('claimed_at');
                $table->index('invitation_sent_at');
            }
            if (! Schema::hasColumn('coupon_beneficiaries', 'last_invitation_sent_at')) {
                $table->timestamp('last_invitation_sent_at')->nullable()->after('invitation_sent_at');
            }
            if (! Schema::hasColumn('coupon_beneficiaries', 'invitation_count')) {
                $table->unsignedInteger('invitation_count')->default(0)->after('last_invitation_sent_at');
            }
            if (! Schema::hasColumn('coupon_beneficiaries', 'activated_at')) {
                $table->timestamp('activated_at')->nullable()->after('invitation_count');
            }
            if (! Schema::hasColumn('coupon_beneficiaries', 'activation_notified_at')) {
                $table->timestamp('activation_notified_at')->nullable()->after('activated_at');
                $table->index('activation_notified_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('coupon_beneficiaries')) {
            return;
        }

        Schema::table('coupon_beneficiaries', function (Blueprint $table) {
            $columns = [
                'invitation_sent_at',
                'last_invitation_sent_at',
                'invitation_count',
                'activated_at',
                'activation_notified_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('coupon_beneficiaries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
