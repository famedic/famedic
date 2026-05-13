<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_admin_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('max_assignment_amount_cents')->nullable();
            $table->unsignedInteger('max_assignments_per_day')->nullable();
            $table->string('authorization_email')->nullable();
            $table->boolean('require_authorization')->default(false);
            $table->timestamps();
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->foreignId('parent_coupon_id')->nullable()->after('id')->constrained('coupons')->nullOnDelete();
            $table->text('description')->nullable()->after('code');
            $table->unsignedInteger('max_beneficiaries')->nullable()->after('remaining_cents');
            $table->string('approval_status', 32)->default('active')->after('is_active');
            $table->string('authorization_code_hash')->nullable()->after('approval_status');
            $table->timestamp('authorization_code_expires_at')->nullable()->after('authorization_code_hash');
            $table->timestamp('authorized_at')->nullable()->after('authorization_code_expires_at');
            $table->foreignId('authorized_by_user_id')->nullable()->after('authorized_at')->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->after('authorized_by_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropForeign(['parent_coupon_id']);
            $table->dropForeign(['authorized_by_user_id']);
            $table->dropForeign(['created_by_user_id']);
            $table->dropForeign(['updated_by_user_id']);
            $table->dropColumn([
                'parent_coupon_id',
                'description',
                'max_beneficiaries',
                'approval_status',
                'authorization_code_hash',
                'authorization_code_expires_at',
                'authorized_at',
                'authorized_by_user_id',
                'created_by_user_id',
                'updated_by_user_id',
            ]);
        });

        Schema::dropIfExists('coupon_admin_settings');
    }
};
