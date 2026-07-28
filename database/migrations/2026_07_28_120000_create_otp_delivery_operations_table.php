<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_delivery_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_key', 64)->unique();
            $table->foreignId('otp_challenge_id')->nullable()->index()->constrained('otp_challenges')->nullOnDelete();
            $table->string('purpose');
            $table->string('status');
            $table->string('primary_channel');
            $table->boolean('fallback_used')->default(false);
            $table->string('provider_alias')->nullable();
            $table->string('result_class')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->uuid('correlation_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_delivery_operations');
    }
};
