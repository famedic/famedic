<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_credit_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('context_id')->constrained('opaque_benefit_contexts')->restrictOnDelete();
            $t->foreignId('source_id')->constrained('coupons')->restrictOnDelete();
            $t->char('actor_hash', 64);
            $t->char('key_hash', 64)->unique();
            $t->char('request_hash', 64);
            $t->char('reference_hash', 64)->unique();
            $t->text('reference_ciphertext');
            $t->char('application_hash', 64);
            $t->char('active_application_hash', 64)->nullable()->unique();
            $t->char('source_stamp', 64);
            $t->char('cart_version_hash', 64);
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('subtotal_cents');
            $t->enum('state', ['held', 'needs_review', 'committed', 'released', 'compensated']);
            $t->timestamp('expires_at');
            $t->foreignId('payment_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $t->foreignId('settlement_id')->nullable()->unique()->constrained('opaque_benefit_settlements')->restrictOnDelete();
            $t->text('purchase_ref_ciphertext')->nullable();
            $t->timestamps();
            $t->index(['source_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_credit_reservations');
    }
};
