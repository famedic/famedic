<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_consumer_otp_issuers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consumer_id')->constrained('api_consumers');
            $t->foreignId('channel_id')->unique()->constrained('api_consumer_channels');
            $t->foreignId('operator_id')->constrained('users');
            $t->boolean('enabled')->default(false);
            $t->string('approval_ref', 128);
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('api_consumer_lifecycle_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('operator_id')->constrained('users');
            $t->string('record_type', 32);
            $t->unsignedBigInteger('record_id');
            $t->string('action', 32);
            $t->string('approval_ref', 128);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_consumer_lifecycle_events');
        Schema::dropIfExists('api_consumer_otp_issuers');
    }
};
