<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_3ds_sessions')) {
            return;
        }

        Schema::create('payment_3ds_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('related');
            $table->string('provider', 32)->default('hey_banco');
            $table->string('flow', 64)->default('token_3ds_charge');
            $table->string('folio', 30);
            $table->string('reference', 128)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MXN');
            $table->string('mode', 8)->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('redirect_url')->nullable();
            $table->text('response_url');
            $table->string('eci', 8)->nullable();
            $table->string('ucaf', 64)->nullable();
            $table->string('xid', 64)->nullable();
            $table->string('auth_code', 64)->nullable();
            $table->string('issuer_code', 16)->nullable();
            $table->string('bnrg_reference', 128)->nullable();
            $table->text('bnrg_text')->nullable();
            $table->string('bnrg_codigo_proc', 8)->nullable();
            $table->string('bnrg_codigo_rechazo', 16)->nullable();
            $table->string('bnrg_card_type', 32)->nullable();
            $table->string('bnrg_account_type', 32)->nullable();
            $table->string('bnrg_issuing_bank', 64)->nullable();
            $table->string('request_hash', 255)->nullable();
            $table->string('response_hash', 255)->nullable();
            $table->boolean('hash_valid')->nullable();
            $table->json('checkout_context')->nullable();
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'folio'], 'payment_3ds_sessions_provider_folio_index');
            $table->index(['provider', 'reference'], 'payment_3ds_sessions_provider_reference_index');
            $table->index('status', 'payment_3ds_sessions_status_index');
            $table->index('payment_method_id', 'payment_3ds_sessions_payment_method_id_index');
            $table->index('payment_attempt_id', 'payment_3ds_sessions_payment_attempt_id_index');
            $table->index('payment_transaction_id', 'payment_3ds_sessions_payment_transaction_id_index');
            $table->index('expires_at', 'payment_3ds_sessions_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_3ds_sessions');
    }
};
