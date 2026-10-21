<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('account_contexts')) {
            return;
        }

        Schema::create('account_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('otp_challenge_id')->constrained('otp_challenges')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->char('phone_hash', 64);
            $table->char('conversation_hash', 64);
            $table->char('channel_event_hash', 64);
            $table->string('channel', 32);
            $table->string('audience', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('verified_at');
            $table->timestamp('invalidated_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'audience', 'expires_at']);
            $table->index(['conversation_hash', 'channel', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_contexts');
    }
};
