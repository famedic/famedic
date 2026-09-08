<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('otp_delivery_operations') && ! Schema::hasColumn('otp_delivery_operations', 'provider_message_id')) {
            Schema::table('otp_delivery_operations', function (Blueprint $table): void {
                $table->string('provider_message_id', 64)->nullable()->index();
                $table->string('sms_delivery_status', 32)->nullable()->index();
                $table->timestamp('sms_delivery_status_at')->nullable();
                $table->string('sms_failure_code', 32)->nullable();
                $table->string('sms_failure_reason', 255)->nullable();
            });
        }

        if (Schema::hasTable('otp_sms_delivery_receipts')) {
            return;
        }

        Schema::create('otp_sms_delivery_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_message_id', 64)->index();
            $table->foreignId('otp_delivery_operation_id')->nullable()->index()->constrained('otp_delivery_operations')->nullOnDelete();
            $table->string('receipt_status', 32)->index();
            $table->string('raw_status', 32)->nullable();
            $table->unsignedTinyInteger('status_rank')->default(0);
            $table->string('failure_code', 32)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->char('idempotency_key', 64)->unique();
            $table->timestamp('received_at')->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }
};
