<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function marketingCampaignIsolatedTableNames(): array
{
    return [
        'marketing_campaign_link_images',
        'marketing_campaign_link_categories',
        'marketing_campaign_link_products',
        'marketing_campaign_collection_items',
        'marketing_campaign_collections',
        'marketing_campaign_link_aliases',
        'marketing_campaign_links',
        'marketing_campaigns',
        'model_has_permissions',
        'role_has_permissions',
        'model_has_roles',
        'permissions',
        'roles',
        'laboratory_tests',
        'laboratory_test_categories',
        'notifications',
        'laboratory_concierges',
        'administrators',
        'customers',
        'users',
    ];
}

function tearDownIsolatedMarketingCampaignSchema(): void
{
    Schema::disableForeignKeyConstraints();

    foreach (marketingCampaignIsolatedTableNames() as $table) {
        Schema::dropIfExists($table);
    }

    Schema::enableForeignKeyConstraints();
}

function bootstrapIsolatedMarketingCampaignSchema(): void
{
    tearDownIsolatedMarketingCampaignSchema();

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('paternal_lastname')->nullable();
        $table->string('maternal_lastname')->nullable();
        $table->string('email')->unique();
        $table->date('birth_date')->nullable();
        $table->string('gender')->nullable();
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('notifications', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('type')->nullable();
        $table->string('title')->nullable();
        $table->text('message')->nullable();
        $table->boolean('is_read')->default(false);
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('administrators', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('laboratory_concierges', function (Blueprint $table) {
        $table->id();
        $table->foreignId('administrator_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
        $table->softDeletes();
    });

    // Stub for HandleInertiaRequests share props (user->customer) on admin Inertia responses.
    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('customerable_type')->nullable();
        $table->unsignedBigInteger('customerable_id')->nullable();
        $table->timestamp('medical_attention_subscription_expires_at')->nullable();
        $table->string('medical_attention_identifier')->nullable();
        $table->boolean('has_odessa_afiliate_account')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('laboratory_test_categories', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('laboratory_tests', function (Blueprint $table) {
        $table->id();
        $table->string('brand', 80);
        $table->string('gda_id')->unique();
        $table->string('name');
        $table->string('other_name')->nullable();
        $table->text('description')->nullable();
        $table->text('elements')->nullable();
        $table->text('common_use')->nullable();
        $table->text('indications')->nullable();
        $table->json('feature_list')->nullable();
        $table->boolean('requires_appointment')->default(false);
        $table->unsignedInteger('public_price_cents');
        $table->unsignedInteger('famedic_price_cents');
        $table->foreignId('laboratory_test_category_id')->constrained()->restrictOnDelete();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('permissions', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->unsignedBigInteger('permission_id')->nullable();
        $table->string('description')->nullable();
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::create('roles', function (Blueprint $table) {
        $table->id();
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
        $table->primary(['permission_id', 'model_id', 'model_type']);
    });

    Schema::create('model_has_roles', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->index(['model_id', 'model_type']);
        $table->primary(['role_id', 'model_id', 'model_type']);
    });

    Schema::create('role_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
        $table->primary(['permission_id', 'role_id']);
    });

    $migration = require database_path('migrations/2026_08_06_230000_create_marketing_campaign_tables.php');
    $migration->up();

    $permissionMigration = require database_path('migrations/2026_08_06_230100_add_marketing_campaign_permissions.php');
    $permissionMigration->up();

    $landingMigration = require database_path('migrations/2026_08_06_230200_add_landing_fields_to_marketing_campaign_links.php');
    $landingMigration->up();

    $commerceMigration = require database_path('migrations/2026_08_06_230300_add_landing_commerce_to_marketing_campaign_links.php');
    $commerceMigration->up();

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
}
