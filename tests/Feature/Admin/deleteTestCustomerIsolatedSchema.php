<?php

/**
 * Esquema SQLite mínimo para tests de eliminación de clientes de prueba.
 */
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function isolatedDeleteTestCustomerTableNames(): array
{
    return [
        'transactionables',
        'transactions',
        'murguia_sync_logs',
        'medical_attention_subscriptions',
        'laboratory_purchases',
        'contacts',
        'customers',
        'regular_accounts',
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'administrators',
        'permissions',
        'roles',
        'sessions',
        'users',
    ];
}

function tearDownIsolatedDeleteTestCustomerSchema(): void
{
    Schema::disableForeignKeyConstraints();

    foreach (isolatedDeleteTestCustomerTableNames() as $table) {
        Schema::dropIfExists($table);
    }

    Schema::enableForeignKeyConstraints();
}

function bootstrapIsolatedDeleteTestCustomerSchema(): void
{
    tearDownIsolatedDeleteTestCustomerSchema();

    Schema::create('sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });

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
        $table->unsignedBigInteger('referred_by')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('phone_verified_at')->nullable();
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
    });

    Schema::create('permissions', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
    });

    Schema::create('role_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
        $table->primary(['permission_id', 'role_id']);
    });

    Schema::create('administrators', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('model_has_roles', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['role_id', 'model_id', 'model_type']);
    });

    Schema::create('model_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['permission_id', 'model_id', 'model_type']);
    });

    Schema::create('regular_accounts', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->nullableMorphs('customerable');
        $table->string('medical_attention_identifier')->nullable();
        $table->timestamp('medical_attention_subscription_expires_at')->nullable();
        $table->string('stripe_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('contacts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
        $table->string('name')->nullable();
        $table->string('paternal_lastname')->nullable();
        $table->string('maternal_lastname')->nullable();
        $table->string('phone')->nullable();
        $table->string('phone_country')->nullable();
        $table->date('birth_date')->nullable();
        $table->string('gender')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('medical_attention_subscriptions', function (Blueprint $table) {
        $table->id();
        $table->dateTime('start_date');
        $table->dateTime('end_date');
        $table->unsignedInteger('price_cents');
        $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
        $table->string('type')->nullable();
        $table->unsignedBigInteger('parent_subscription_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('murguia_sync_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
        $table->unsignedBigInteger('triggered_by')->nullable();
        $table->string('action', 32);
        $table->json('request_payload')->nullable();
        $table->json('response_payload')->nullable();
        $table->string('status', 32);
        $table->timestamps();
    });

    Schema::create('laboratory_purchases', function (Blueprint $table) {
        $table->id();
        $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
        $table->string('brand')->nullable();
        $table->string('gda_order_id')->nullable();
        $table->string('name')->nullable();
        $table->string('paternal_lastname')->nullable();
        $table->string('maternal_lastname')->nullable();
        $table->string('phone')->nullable();
        $table->string('phone_country')->nullable();
        $table->date('birth_date')->nullable();
        $table->string('gender')->nullable();
        $table->string('street')->nullable();
        $table->string('number')->nullable();
        $table->string('neighborhood')->nullable();
        $table->string('state')->nullable();
        $table->string('city')->nullable();
        $table->string('zipcode')->nullable();
        $table->unsignedInteger('total_cents')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('transactions', function (Blueprint $table) {
        $table->id();
        $table->unsignedInteger('transaction_amount_cents')->default(0);
        $table->string('payment_method')->nullable();
        $table->string('gateway')->nullable();
        $table->json('details')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('transactionables', function (Blueprint $table) {
        $table->id();
        $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
        $table->morphs('transactionable');
    });
}
