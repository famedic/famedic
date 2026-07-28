<?php

use App\Support\Migrations\MinimumTableContract;
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
        if (Schema::hasTable('otp_rate_limits')) {
            MinimumTableContract::assertCompatible('otp_rate_limits', $this->rateLimitsContract());
        } else {
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
        }

        if (Schema::hasTable('otp_abuse_events')) {
            MinimumTableContract::assertCompatible('otp_abuse_events', $this->abuseEventsContract());
        } else {
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
    }

    /**
     * Conservative no-op rollback under schema drift.
     *
     * up() may accept pre-existing compatible otp_rate_limits / otp_abuse_events
     * tables without creating them. Without a durable authorship record,
     * dropIfExists would destroy those tables and their data on migrate:rollback.
     * Both tables are therefore retained.
     */
    public function down(): void
    {
        // Intentionally non-destructive under schema drift.
    }

    /**
     * @return array{
     *     columns: array<string, array{types: list<string>, nullable?: bool|null}>,
     *     indexes: list<array{name: string, columns: list<string>, unique?: bool}>,
     *     foreign_keys: list<array{columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_delete?: string|list<string>}>
     * }
     */
    private function rateLimitsContract(): array
    {
        return [
            'columns' => [
                'id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'bucket_type' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'bucket_key_hash' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'purpose' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'window_started_at' => ['types' => ['timestamp', 'datetime'], 'nullable' => false],
                'request_count' => ['types' => ['integer', 'int', 'bigint'], 'nullable' => false],
                'last_challenge_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
            ],
            'indexes' => [
                [
                    'name' => 'otp_rate_limits_bucket_unique',
                    'columns' => ['bucket_type', 'bucket_key_hash', 'purpose'],
                    'unique' => true,
                ],
                ['name' => 'otp_rate_limits_purpose_window_index', 'columns' => ['purpose', 'window_started_at']],
                ['name' => 'otp_rate_limits_type_purpose_block_index', 'columns' => ['bucket_type', 'purpose', 'blocked_until']],
            ],
            'foreign_keys' => [
                [
                    'columns' => ['last_challenge_id'],
                    'referenced_table' => 'otp_challenges',
                    'referenced_columns' => ['id'],
                    'on_delete' => ['set null', 'null'],
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     columns: array<string, array{types: list<string>, nullable?: bool|null}>,
     *     indexes: list<array{name: string, columns: list<string>, unique?: bool}>,
     *     foreign_keys: list<array{columns: list<string>, referenced_table: string, referenced_columns: list<string>, on_delete?: string|list<string>}>
     * }
     */
    private function abuseEventsContract(): array
    {
        return [
            'columns' => [
                'id' => ['types' => ['bigint', 'integer'], 'nullable' => false],
                'decision' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'purpose' => ['types' => ['string', 'varchar', 'char'], 'nullable' => false],
                'otp_challenge_id' => ['types' => ['bigint', 'integer'], 'nullable' => true],
                // useCurrent() yields NOT NULL on SQLite/MySQL; nullability is not cosmetic here.
                'created_at' => ['types' => ['timestamp', 'datetime'], 'nullable' => false],
            ],
            'indexes' => [
                ['name' => 'otp_abuse_events_identity_purpose_created_index', 'columns' => ['identity_key_hash', 'purpose', 'created_at']],
                ['name' => 'otp_abuse_events_ip_purpose_created_index', 'columns' => ['ip_key_hash', 'purpose', 'created_at']],
                ['name' => 'otp_abuse_events_decision_created_index', 'columns' => ['decision', 'created_at']],
            ],
            'foreign_keys' => [
                [
                    'columns' => ['otp_challenge_id'],
                    'referenced_table' => 'otp_challenges',
                    'referenced_columns' => ['id'],
                    'on_delete' => ['set null', 'null'],
                ],
            ],
        ];
    }
};
