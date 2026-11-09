<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_backend_challenges', function (Blueprint $t) {
            $t->id();
            $t->char('challenge_hash', 64)->unique();
            $t->foreignId('consumer_id')->constrained('api_consumers');
            $t->foreignId('channel_id')->constrained('api_consumer_channels');
            $t->timestamp('expires_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_backend_challenges');
    }
};
