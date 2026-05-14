<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('laboratory_purchase_id')->constrained('laboratory_purchases')->cascadeOnDelete();
            $table->string('code');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->enum('status', ['pending', 'verified', 'expired', 'failed'])->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'laboratory_purchase_id', 'status'], 'otp_codes_user_purchase_status_index');
            $table->index(['laboratory_purchase_id', 'created_at'], 'otp_codes_purchase_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
