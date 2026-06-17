<?php

/**
 * Esquema SQLite mínimo compartido para tests aislados del módulo Saldo a Favor v2.
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function bootstrapIsolatedCouponModuleSchema(): void
{
    Schema::disableForeignKeyConstraints();
    tearDownIsolatedCouponModuleSchema();

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('email')->unique();
        $table->string('password')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamps();
    });

    Schema::create('coupon_concepts', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->text('description')->nullable();
        $table->timestamps();
    });

    Schema::create('coupons', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('parent_coupon_id')->nullable();
        $table->string('code')->nullable();
        $table->text('description')->nullable();
        $table->unsignedBigInteger('coupon_concept_id')->nullable();
        $table->string('concept_other')->nullable();
        $table->unsignedInteger('amount_cents');
        $table->unsignedInteger('remaining_cents')->default(0);
        $table->timestamp('valid_from')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->unsignedInteger('min_purchase_cents')->nullable();
        $table->unsignedInteger('max_beneficiaries')->nullable();
        $table->string('type')->default('balance');
        $table->string('approval_status')->default('active');
        $table->boolean('is_active')->default(true);
        $table->unsignedBigInteger('created_by_user_id')->nullable();
        $table->unsignedBigInteger('updated_by_user_id')->nullable();
        $table->timestamps();
    });

    Schema::create('coupon_user', function (Blueprint $table) {
        $table->id();
        $table->foreignId('coupon_id')->constrained();
        $table->foreignId('user_id')->constrained();
        $table->timestamp('assigned_at')->nullable();
        $table->timestamp('used_at')->nullable();
    });

    Schema::create('notifications', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
        $table->string('type');
        $table->string('title');
        $table->text('message');
        $table->boolean('is_read')->default(false);
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('coupon_admin_settings', function (Blueprint $table) {
        $table->id();
        $table->unsignedInteger('base_amount_cents')->default(50_000);
        $table->unsignedInteger('max_assignment_amount_cents')->nullable();
        $table->unsignedInteger('max_assignments_per_day')->nullable();
        $table->string('authorization_email')->nullable();
        $table->boolean('require_authorization')->default(false);
        $table->unsignedInteger('amount_threshold_cents')->nullable();
        $table->unsignedInteger('required_approvals_by_amount')->default(0);
        $table->unsignedInteger('mass_campaign_threshold')->nullable();
        $table->boolean('superadmin_bypass_approvals')->default(true);
        $table->timestamps();
    });

    Schema::create('coupon_audit_logs', function (Blueprint $table) {
        $table->id();
        $table->string('type')->nullable();
        $table->string('action')->nullable();
        $table->string('status')->nullable();
        $table->foreignId('actor_user_id')->nullable()->constrained('users');
        $table->foreignId('coupon_id')->nullable()->constrained();
        $table->unsignedBigInteger('coupon_approval_request_id')->nullable();
        $table->json('context')->nullable();
        $table->timestamps();
    });

    Schema::create('coupon_beneficiaries', function (Blueprint $table) {
        $table->id();
        $table->foreignId('parent_coupon_id')->constrained('coupons')->cascadeOnDelete();
        $table->foreignId('child_coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
        $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->string('email');
        $table->string('email_normalized')->index();
        $table->string('first_name')->nullable();
        $table->string('paternal_lastname')->nullable();
        $table->string('maternal_lastname')->nullable();
        $table->string('status')->index();
        $table->string('source')->index();
        $table->string('import_batch_id')->nullable()->index();
        $table->timestamp('assigned_at')->nullable();
        $table->timestamp('claimed_at')->nullable();
        $table->timestamp('invitation_sent_at')->nullable();
        $table->timestamp('last_invitation_sent_at')->nullable();
        $table->unsignedInteger('invitation_count')->default(0);
        $table->timestamp('activated_at')->nullable();
        $table->timestamp('activation_notified_at')->nullable();
        $table->timestamp('cancelled_at')->nullable();
        $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
        $table->unique(['parent_coupon_id', 'email_normalized']);
    });

    Schema::enableForeignKeyConstraints();
}

function tearDownIsolatedCouponModuleSchema(): void
{
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('coupon_audit_logs');
    Schema::dropIfExists('coupon_beneficiaries');
    Schema::dropIfExists('coupon_admin_settings');
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('coupon_user');
    Schema::dropIfExists('coupons');
    Schema::dropIfExists('coupon_concepts');
    Schema::dropIfExists('users');
    Schema::enableForeignKeyConstraints();
}

function bootstrapIsolatedCouponReversalSchema(): void
{
    if (Schema::hasTable('laboratory_purchases')) {
        return;
    }

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained();
        $table->string('stripe_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('coupon_transactions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('coupon_id')->constrained();
        $table->foreignId('user_id')->constrained();
        $table->string('purchase_type');
        $table->unsignedBigInteger('purchase_id');
        $table->unsignedInteger('amount_used_cents');
        $table->timestamp('reversed_at')->nullable();
        $table->foreignId('reversed_by_user_id')->nullable()->constrained('users');
        $table->string('reversal_reason')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('laboratory_purchases', function (Blueprint $table) {
        $table->id();
        $table->foreignId('customer_id')->nullable()->constrained();
        $table->unsignedInteger('total_cents')->default(0);
        $table->unsignedInteger('coupon_discount_cents')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
}

function tearDownIsolatedCouponReversalSchema(): void
{
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('coupon_transactions');
    Schema::dropIfExists('laboratory_purchases');
    Schema::dropIfExists('customers');
    Schema::enableForeignKeyConstraints();
}
