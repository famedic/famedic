<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('otp_sms_delivery_receipt_applications')) {
            return;
        }

        Schema::create('otp_sms_delivery_receipt_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('otp_sms_delivery_receipt_id')
                ->unique()
                ->constrained('otp_sms_delivery_receipts')
                ->cascadeOnDelete();
            $table->foreignId('otp_delivery_operation_id')
                ->index()
                ->constrained('otp_delivery_operations')
                ->cascadeOnDelete();
            $table->timestamp('applied_at')->index();
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }
};
