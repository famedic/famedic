<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_authentication_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('attempt_uuid')->unique();
            $table->string('support_reference')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('operation_type', 80);
            $table->string('provider', 80);
            $table->string('status', 80);
            $table->string('merchant_reference')->unique();
            $table->foreignId('efevoo_3ds_session_id')->nullable()->constrained('efevoo_3ds_sessions')->nullOnDelete();
            $table->foreignId('retry_of_attempt_id')->nullable()->constrained('payment_authentication_attempts')->nullOnDelete();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('initiated_by', 80)->default('customer');
            $table->string('failure_category', 80)->nullable();
            $table->string('failure_origin', 80)->nullable();
            $table->string('provider_code', 80)->nullable();
            $table->string('provider_message', 500)->nullable();
            $table->string('provider_order_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_provider_call_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('duplicate_request_count')->default(0);
            $table->timestamps();

            $table->index(['customer_id', 'status'], 'paa_customer_status_idx');
            $table->index(['provider', 'provider_order_id'], 'paa_provider_order_idx');
            $table->index(['operation_type', 'status', 'expires_at'], 'paa_operation_status_expires_idx');
        });

        Schema::table('efevoo_3ds_sessions', function (Blueprint $table) {
            $table->foreignId('payment_authentication_attempt_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('payment_authentication_attempts')
                ->nullOnDelete();

            $table->index('payment_authentication_attempt_id', 'e3ds_auth_attempt_idx');
        });
    }

    public function down(): void
    {
        Schema::table('efevoo_3ds_sessions', function (Blueprint $table) {
            $table->dropForeign(['payment_authentication_attempt_id']);
            $table->dropIndex('e3ds_auth_attempt_idx');
            $table->dropColumn('payment_authentication_attempt_id');
        });

        Schema::dropIfExists('payment_authentication_attempts');
    }
};
