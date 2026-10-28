<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_confirmation_rollouts', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->timestamp('activated_at');
        });
        Schema::create('laboratory_purchase_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratory_purchase_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('idempotency_key', 120)->unique();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamp('deadline_at');
            $table->string('status', 32)->default('waiting');
            $table->string('content_kind', 20)->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->longText('payload')->nullable(); // Encrypted rendered content, never logged.
            $table->uuid('attempt_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('preparation_attempts')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('transport_status', 32)->default('not_attempted');
            $table->string('transport_message_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at'], 'lab_confirmation_due');
            $table->index(['laboratory_purchase_id', 'event_type'], 'lab_confirmation_purchase_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_purchase_confirmations');
        Schema::dropIfExists('laboratory_confirmation_rollouts');
    }
};
