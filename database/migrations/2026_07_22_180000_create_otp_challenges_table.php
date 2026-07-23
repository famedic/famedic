<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_key', 191)->nullable();
            $table->string('purpose', 64)->index();
            $table->string('channel', 16);
            $table->string('destination_normalized', 191)->nullable();
            $table->string('destination_masked', 64)->nullable();
            $table->string('code_hash', 255);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('invalidated_at')->nullable()->index();
            $table->string('invalidated_reason', 64)->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->unsignedTinyInteger('send_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->string('context_type', 64)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'expires_at'], 'otp_challenges_user_purpose_expires_index');
            $table->index(
                ['subject_type', 'subject_key', 'purpose', 'expires_at'],
                'otp_challenges_subject_purpose_expires_index'
            );
            $table->index(
                ['purpose', 'context_type', 'context_id', 'expires_at'],
                'otp_challenges_purpose_context_expires_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
