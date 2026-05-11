<?php

use App\Models\Administrator;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('coupon_admin_settings', 'base_amount_cents')) {
            Schema::table('coupon_admin_settings', function (Blueprint $table) {
                $table->unsignedInteger('base_amount_cents')->default(50000)->after('id');
            });
        }

        if (! Schema::hasColumn('coupon_admin_settings', 'amount_threshold_cents')) {
            Schema::table('coupon_admin_settings', function (Blueprint $table) {
                $table->unsignedInteger('amount_threshold_cents')->nullable()->after('base_amount_cents');
            });
        }

        if (! Schema::hasColumn('coupon_admin_settings', 'required_approvals_by_amount')) {
            Schema::table('coupon_admin_settings', function (Blueprint $table) {
                $table->unsignedInteger('required_approvals_by_amount')->default(0)->after('amount_threshold_cents');
            });
        }

        if (! Schema::hasColumn('coupon_admin_settings', 'mass_campaign_threshold')) {
            Schema::table('coupon_admin_settings', function (Blueprint $table) {
                $table->unsignedInteger('mass_campaign_threshold')->nullable()->after('required_approvals_by_amount');
            });
        }

        if (! Schema::hasColumn('coupon_admin_settings', 'superadmin_bypass_approvals')) {
            Schema::table('coupon_admin_settings', function (Blueprint $table) {
                $table->boolean('superadmin_bypass_approvals')->default(true)->after('mass_campaign_threshold');
            });
        }

        if (! Schema::hasTable('coupon_beneficiary_approval_rules')) {
            Schema::create('coupon_beneficiary_approval_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('min_beneficiaries');
                $table->unsignedInteger('max_beneficiaries')->nullable();
                $table->unsignedInteger('required_approvals')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('coupon_approval_requests')) {
            Schema::create('coupon_approval_requests', function (Blueprint $table) {
                $table->id();
                $table->string('type', 32); // settings|assignment
                $table->string('status', 32)->default('pending'); // pending|approved|rejected|executed
                $table->foreignIdFor(User::class, 'requested_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignIdFor(User::class, 'rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignIdFor(Coupon::class)->nullable()->constrained('coupons')->nullOnDelete();
                $table->unsignedInteger('required_approvals')->default(0);
                $table->unsignedInteger('current_approvals')->default(0);
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('coupon_approval_request_authorizers')) {
            Schema::create('coupon_approval_request_authorizers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('coupon_approval_request_id')
                    ->constrained('coupon_approval_requests', 'id', 'fk_car_authorizers_car_id')
                    ->cascadeOnDelete();
                $table->foreignIdFor(Administrator::class)->constrained('administrators')->cascadeOnDelete();
                $table->foreignIdFor(User::class)->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 32)->default('pending'); // pending|approved|rejected
                $table->timestamp('acted_at')->nullable();
                $table->text('comment')->nullable();
                $table->timestamps();
                $table->unique(['coupon_approval_request_id', 'administrator_id'], 'coupon_request_authorizer_unique');
            });
        }

        if (! Schema::hasTable('coupon_audit_logs')) {
            Schema::create('coupon_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->string('type', 32); // configuration|assignment
                $table->string('action', 64);
                $table->string('status', 32)->default('completed');
                $table->foreignIdFor(User::class, 'actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignIdFor(User::class, 'approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignIdFor(Coupon::class)->nullable()->constrained('coupons')->nullOnDelete();
                $table->foreignId('coupon_approval_request_id')->nullable()->constrained('coupon_approval_requests')->nullOnDelete();
                $table->json('context')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_audit_logs');
        Schema::dropIfExists('coupon_approval_request_authorizers');
        Schema::dropIfExists('coupon_approval_requests');
        Schema::dropIfExists('coupon_beneficiary_approval_rules');

        $columns = [
            'base_amount_cents',
            'amount_threshold_cents',
            'required_approvals_by_amount',
            'mass_campaign_threshold',
            'superadmin_bypass_approvals',
        ];

        $existingColumns = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn('coupon_admin_settings', $column)));
        if (count($existingColumns) > 0) {
            Schema::table('coupon_admin_settings', function (Blueprint $table) use ($existingColumns) {
                $table->dropColumn($existingColumns);
            });
        }
    }
};
