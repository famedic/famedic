<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_authentication_recovery_contexts', function (Blueprint $table) {
            $table->id();
            $table->uuid('context_uuid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('context_type', 80);
            $table->string('status', 80);
            $table->string('return_route_name', 120)->nullable();
            $table->json('context_data')->nullable();
            $table->foreignId('cart_id')->nullable()->constrained('carts')->nullOnDelete();
            // Nullable IDs without FK to avoid a circular migration with payment_authentication_attempts.
            $table->unsignedBigInteger('root_authentication_attempt_id')->nullable();
            $table->unsignedBigInteger('recovered_by_authentication_attempt_id')->nullable();
            $table->unsignedBigInteger('recovered_transaction_id')->nullable();
            $table->string('recovery_method', 80)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('card_verified_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status'], 'parc_customer_status_idx');
            $table->index(['customer_id', 'context_type', 'status'], 'parc_customer_type_status_idx');
            $table->index(['status', 'expires_at'], 'parc_status_expires_idx');
            $table->index('root_authentication_attempt_id', 'parc_root_attempt_idx');
        });

        Schema::table('payment_authentication_recovery_contexts', function (Blueprint $table) {
            $table->foreign('recovered_transaction_id', 'parc_recovered_tx_fk')
                ->references('id')
                ->on('transactions')
                ->nullOnDelete();
        });

        Schema::table('payment_authentication_attempts', function (Blueprint $table) {
            $table->foreignId('recovery_context_id')
                ->nullable()
                ->after('retry_of_attempt_id')
                ->constrained('payment_authentication_recovery_contexts')
                ->nullOnDelete();

            $table->index('recovery_context_id', 'paa_recovery_context_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payment_authentication_recovery_contexts', function (Blueprint $table) {
            $table->dropForeign('parc_recovered_tx_fk');
        });

        Schema::table('payment_authentication_attempts', function (Blueprint $table) {
            $table->dropForeign(['recovery_context_id']);
            $table->dropIndex('paa_recovery_context_idx');
            $table->dropColumn('recovery_context_id');
        });

        Schema::dropIfExists('payment_authentication_recovery_contexts');
    }
};
