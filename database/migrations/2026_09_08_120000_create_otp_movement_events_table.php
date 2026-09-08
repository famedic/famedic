<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('otp_movement_events')) {
            return;
        }

        Schema::create('otp_movement_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->string('movement_key', 128)->index();
            $table->string('flow', 64)->index();
            $table->string('operation', 32)->index();
            $table->string('stage', 64)->index();
            $table->string('status', 32)->index();
            $table->string('channel', 16)->nullable()->index();
            $table->string('destination_masked', 128)->nullable();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->index()->constrained('customers')->nullOnDelete();
            $table->uuid('challenge_public_id')->nullable()->index();
            $table->string('correlation_id', 128)->nullable()->index();
            $table->char('idempotency_key_fingerprint', 16)->nullable()->index();
            $table->string('provider_alias', 64)->nullable()->index();
            $table->string('provider_result_class', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->boolean('is_resend')->default(false);
            $table->boolean('is_decoy')->default(false);
            $table->boolean('is_replay')->default(false);
            $table->boolean('is_idempotency_conflict')->default(false);
            $table->string('endpoint', 255)->nullable()->index();
            $table->string('error_code', 64)->nullable();
            $table->string('technical_message', 512)->nullable();
            $table->foreignId('otp_challenge_id')->nullable()->index()->constrained('otp_challenges')->nullOnDelete();
            $table->foreignId('otp_delivery_operation_id')->nullable()->index()->constrained('otp_delivery_operations')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['occurred_at', 'flow']);
            $table->index(['occurred_at', 'status']);
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }
};
