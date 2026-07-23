<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-A3 — Atomic anti-abuse counters / blocks (identity + IP buckets).
 *
 * Stores only HMAC digests for identity and IP material — never plaintext IP,
 * destination, or OTP codes. Compatible with MySQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_rate_limits', function (Blueprint $table) {
            $table->id();
            /** identity | ip */
            $table->string('bucket_type', 16);
            /** HMAC-SHA256 hex (64 chars) of canonical identity or normalized IP. */
            $table->string('bucket_key_hash', 64);
            $table->string('purpose', 64);
            $table->timestamp('window_started_at');
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamp('last_allowed_at')->nullable();
            $table->timestamp('blocked_until')->nullable()->index();
            $table->foreignId('last_challenge_id')
                ->nullable()
                ->constrained('otp_challenges')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['bucket_type', 'bucket_key_hash', 'purpose'],
                'otp_rate_limits_bucket_unique'
            );
            $table->index(
                ['purpose', 'window_started_at'],
                'otp_rate_limits_purpose_window_index'
            );
            $table->index(
                ['bucket_type', 'purpose', 'blocked_until'],
                'otp_rate_limits_type_purpose_block_index'
            );
        });

        Schema::create('otp_abuse_events', function (Blueprint $table) {
            $table->id();
            /** allowed|cooldown|identity_limited|ip_limited|blocked|block_started|resend_allowed|max_attempts|blocked_request */
            $table->string('decision', 32);
            $table->string('error_code', 32)->nullable();
            $table->string('purpose', 64)->index();
            $table->string('identity_key_hash', 64)->nullable();
            $table->string('ip_key_hash', 64)->nullable();
            /** identity|ip|both|challenge|none */
            $table->string('scope', 16)->nullable();
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->foreignId('otp_challenge_id')
                ->nullable()
                ->constrained('otp_challenges')
                ->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['identity_key_hash', 'purpose', 'created_at'],
                'otp_abuse_events_identity_purpose_created_index'
            );
            $table->index(
                ['ip_key_hash', 'purpose', 'created_at'],
                'otp_abuse_events_ip_purpose_created_index'
            );
            $table->index(['decision', 'created_at'], 'otp_abuse_events_decision_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_abuse_events');
        Schema::dropIfExists('otp_rate_limits');
    }
};
