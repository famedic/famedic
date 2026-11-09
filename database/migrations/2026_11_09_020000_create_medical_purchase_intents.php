<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_purchase_intents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('customers');
            $t->foreignId('reservation_id')->unique()->constrained('medical_credit_reservations');
            $t->foreignId('transaction_id')->unique()->constrained('transactions');
            $t->foreignId('membership_id')->nullable()->constrained('medical_attention_subscriptions');
            $t->char('actor_hash', 64);
            $t->char('key_hash', 64)->unique();
            $t->string('reference', 64)->unique();
            $t->string('provider', 24);
            $t->string('state', 32);
            $t->timestamp('reverse_started_at')->nullable();
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('credit_cents');
            $t->unsignedBigInteger('cash_cents');
            $t->timestamps();
        });
        Schema::create('medical_payment_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intent_id')->constrained('medical_purchase_intents');
            $t->string('kind', 24);
            $t->string('provider', 24);
            $t->char('event_hash', 64);
            $t->unsignedBigInteger('cash_cents');
            $t->char('currency', 3);
            $t->timestamp('created_at');
            $t->unique(['intent_id', 'kind']);
            $t->unique(['provider', 'kind', 'event_hash'], 'medical_provider_event_once');
        });
        Schema::create('medical_settlement_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intent_id')->constrained('medical_purchase_intents');
            $t->string('kind', 24);
            $t->string('state', 24)->default('pending');
            $t->timestamps();
            $t->unique(['intent_id', 'kind']);
        });
    }

    public function down(): void
    {
        foreach (['medical_settlement_outbox', 'medical_payment_verifications', 'medical_purchase_intents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
