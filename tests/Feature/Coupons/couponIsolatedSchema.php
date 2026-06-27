<?php

/**
 * Esquema SQLite mínimo compartido para tests aislados del módulo Saldo a Favor.
 *
 * Cubre fases A, B1, B2a, B2b, MC-1, MC-2a, MC-2b y reverso de laboratorio.
 *
 * @see docs/modulo-saldo-a-favor-creditos-cupones.md
 */
use App\Models\CouponAdminSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function isolatedCouponModuleTableNames(): array
{
    return [
        'coupon_audit_logs',
        'notifications',
        'coupon_beneficiaries',
        'coupon_transactions',
        'coupon_user',
        'laboratory_purchases',
        'customers',
        'coupons',
        'coupon_concepts',
        'coupon_admin_settings',
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'administrators',
        'permissions',
        'roles',
        'users',
    ];
}

function isolatedCouponReversalTableNames(): array
{
    return [
        'laboratory_purchases',
        'customers',
    ];
}

function isolatedCouponAdminPermissionTableNames(): array
{
    return [
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'administrators',
        'permissions',
        'roles',
    ];
}

function isolatedCouponDropTables(array $tables): void
{
    Schema::disableForeignKeyConstraints();

    foreach ($tables as $table) {
        Schema::dropIfExists($table);
    }

    Schema::enableForeignKeyConstraints();
}

function tearDownIsolatedCouponModuleSchema(): void
{
    isolatedCouponDropTables(isolatedCouponModuleTableNames());
}

function tearDownIsolatedCouponReversalSchema(): void
{
    isolatedCouponDropTables(isolatedCouponReversalTableNames());
}

function bootstrapIsolatedCouponModuleSchema(): void
{
    tearDownIsolatedCouponModuleSchema();

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('paternal_lastname')->nullable();
        $table->string('maternal_lastname')->nullable();
        $table->string('email')->unique();
        $table->string('phone')->nullable();
        $table->string('phone_country')->nullable();
        $table->date('birth_date')->nullable();
        $table->string('gender')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('phone_verified_at')->nullable();
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('notifications', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('type', 64)->index();
        $table->string('title');
        $table->text('message');
        $table->boolean('is_read')->default(false);
        $table->timestamp('created_at')->useCurrent();
    });

    Schema::create('coupon_concepts', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->text('description')->nullable();
        $table->timestamps();
    });

    Schema::create('coupons', function (Blueprint $table) {
        $table->id();
        $table->foreignId('parent_coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
        $table->string('code')->nullable();
        $table->text('description')->nullable();
        $table->unsignedBigInteger('coupon_concept_id')->nullable();
        $table->string('concept_other')->nullable();
        $table->unsignedInteger('amount_cents');
        $table->unsignedInteger('remaining_cents');
        $table->timestamp('valid_from')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->unsignedInteger('min_purchase_cents')->nullable();
        $table->unsignedInteger('max_beneficiaries')->nullable();
        $table->string('type')->default('balance');
        $table->boolean('is_active')->default(true);
        $table->string('approval_status')->default('active');
        $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
    });

    Schema::create('coupon_user', function (Blueprint $table) {
        $table->id();
        $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->timestamp('assigned_at')->useCurrent();
        $table->timestamp('used_at')->nullable();
        $table->unique(['coupon_id', 'user_id']);
    });

    Schema::create('coupon_transactions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->string('purchase_type', 32);
        $table->unsignedBigInteger('purchase_id');
        $table->unsignedInteger('amount_used_cents');
        $table->timestamp('created_at')->useCurrent();
        $table->timestamp('reversed_at')->nullable();
        $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->string('reversal_reason')->nullable();
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
        $table->string('source')->default('manual')->index();
        $table->string('import_batch_id')->nullable()->index();
        $table->timestamp('assigned_at')->nullable();
        $table->timestamp('claimed_at')->nullable();
        $table->timestamp('cancelled_at')->nullable();
        $table->timestamp('invitation_sent_at')->nullable()->index();
        $table->timestamp('last_invitation_sent_at')->nullable();
        $table->unsignedInteger('invitation_count')->default(0);
        $table->timestamp('activated_at')->nullable();
        $table->timestamp('activation_notified_at')->nullable()->index();
        $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();

        $table->unique(['parent_coupon_id', 'email_normalized']);
        $table->index('user_id');
        $table->index('child_coupon_id');
    });

    Schema::create('coupon_audit_logs', function (Blueprint $table) {
        $table->id();
        $table->string('type', 32);
        $table->string('action', 64);
        $table->string('status', 32)->default('completed');
        $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
        $table->unsignedBigInteger('coupon_approval_request_id')->nullable();
        $table->json('context')->nullable();
        $table->timestamps();
    });

    Schema::create('coupon_admin_settings', function (Blueprint $table) {
        $table->id();
        $table->unsignedInteger('base_amount_cents')->default(50000);
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

    CouponAdminSettings::query()->create([
        'id' => 1,
        'base_amount_cents' => 50000,
        'require_authorization' => false,
        'required_approvals_by_amount' => 0,
        'superadmin_bypass_approvals' => true,
    ]);
}

function bootstrapIsolatedCouponReversalSchema(): void
{
    tearDownIsolatedCouponReversalSchema();

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('stripe_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
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

function bootstrapIsolatedCouponBeneficiaryReportSchema(): void
{
    if (Schema::hasTable('customers')) {
        return;
    }

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('stripe_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

function bootstrapIsolatedCouponRevocationAdminSchema(): void
{
    isolatedCouponDropTables(isolatedCouponAdminPermissionTableNames());

    Schema::create('permissions', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::create('roles', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::create('model_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->index(['model_id', 'model_type']);
        $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_primary');
    });

    Schema::create('model_has_roles', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->index(['model_id', 'model_type']);
        $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_primary');
    });

    Schema::create('role_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
        $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        $table->primary(['permission_id', 'role_id']);
    });

    Schema::create('administrators', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
        $table->softDeletes();
    });
}

/**
 * Alias MC-2a: módulo completo + tablas admin para permisos en endpoints.
 */
function bootstrapIsolatedCouponRevocationSchema(): void
{
    bootstrapIsolatedCouponModuleSchema();
    bootstrapIsolatedCouponRevocationAdminSchema();
}
