<?php

use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @return array<string, array{
 *     path: string,
 *     table: string,
 *     seedCompatible: callable(Blueprint): void,
 *     breakIncompatible: callable(): void,
 *     insertProbe: callable(): array{column: string, value: mixed}
 * }>
 */
function akubicaCreateMigrationCases(): array
{
    return [
        'akubica_checkout_links' => [
            'path' => 'migrations/2026_06_10_120000_create_akubica_checkout_links_table.php',
            'table' => 'akubica_checkout_links',
            'seedCompatible' => static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->string('token_hash', 64)->unique();
                $table->string('laboratory_brand', 32);
                $table->timestamp('expires_at')->index();
                $table->timestamp('used_at')->nullable();
                $table->unsignedBigInteger('created_by_token_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index('customer_id');
                $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            },
            'breakIncompatible' => static function (): void {
                Schema::dropIfExists('akubica_checkout_links');
                Schema::create('akubica_checkout_links', function (Blueprint $table) {
                    $table->id();
                    $table->string('token_hash', 64);
                });
            },
            'insertProbe' => static function (): array {
                $customer = User::factory()->withRegularCustomer()->create()->customer;
                $tokenHash = hash('sha256', 'probe-checkout-'.Str::uuid());
                DB::table('akubica_checkout_links')->insert([
                    'customer_id' => $customer->id,
                    'token_hash' => $tokenHash,
                    'laboratory_brand' => 'olab',
                    'expires_at' => now()->addHour(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['column' => 'token_hash', 'value' => $tokenHash];
            },
        ],
        'otp_challenges' => [
            'path' => 'migrations/2026_07_22_180000_create_otp_challenges_table.php',
            'table' => 'otp_challenges',
            'seedCompatible' => static function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('subject_type', 32)->nullable();
                $table->string('subject_key', 191)->nullable();
                $table->string('purpose', 64);
                $table->string('channel', 16);
                $table->string('destination_normalized', 191)->nullable();
                $table->string('destination_masked', 64)->nullable();
                $table->string('code_hash', 255);
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamp('invalidated_at')->nullable();
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
            },
            'breakIncompatible' => static function (): void {
                Schema::dropIfExists('otp_challenges');
                Schema::create('otp_challenges', function (Blueprint $table) {
                    $table->id();
                    $table->string('purpose');
                });
            },
            'insertProbe' => static function (): array {
                $publicId = (string) Str::uuid();
                DB::table('otp_challenges')->insert([
                    'public_id' => $publicId,
                    'purpose' => 'akubica_register',
                    'channel' => 'sms',
                    'code_hash' => hash('sha256', 'probe-code'),
                    'expires_at' => now()->addMinutes(10),
                    'failed_attempts' => 0,
                    'max_attempts' => 5,
                    'send_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['column' => 'public_id', 'value' => $publicId];
            },
        ],
        'otp_rate_limits' => [
            'path' => 'migrations/2026_07_23_120000_create_otp_rate_limits_table.php',
            'table' => 'otp_rate_limits',
            'seedCompatible' => static function (Blueprint $table): void {
                $table->id();
                $table->string('bucket_type', 16);
                $table->string('bucket_key_hash', 64);
                $table->string('purpose', 64);
                $table->timestamp('window_started_at');
                $table->unsignedInteger('request_count')->default(0);
                $table->timestamp('last_allowed_at')->nullable();
                $table->timestamp('blocked_until')->nullable();
                $table->foreignId('last_challenge_id')->nullable()->constrained('otp_challenges')->nullOnDelete();
                $table->timestamps();
                $table->unique(['bucket_type', 'bucket_key_hash', 'purpose'], 'otp_rate_limits_bucket_unique');
                $table->index(['purpose', 'window_started_at'], 'otp_rate_limits_purpose_window_index');
                $table->index(['bucket_type', 'purpose', 'blocked_until'], 'otp_rate_limits_type_purpose_block_index');
            },
            'breakIncompatible' => static function (): void {
                Schema::dropIfExists('otp_rate_limits');
                Schema::create('otp_rate_limits', function (Blueprint $table) {
                    $table->id();
                    $table->string('bucket_type', 16);
                });
            },
            'insertProbe' => static function (): array {
                $bucketKey = hash('sha256', 'probe-rate-'.Str::uuid());
                DB::table('otp_rate_limits')->insert([
                    'bucket_type' => 'identity',
                    'bucket_key_hash' => $bucketKey,
                    'purpose' => 'akubica_register',
                    'window_started_at' => now(),
                    'request_count' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['column' => 'bucket_key_hash', 'value' => $bucketKey];
            },
        ],
        'otp_abuse_events' => [
            'path' => 'migrations/2026_07_23_120000_create_otp_rate_limits_table.php',
            'table' => 'otp_abuse_events',
            'seedCompatible' => static function (Blueprint $table): void {
                $table->id();
                $table->string('decision', 32);
                $table->string('error_code', 32)->nullable();
                $table->string('purpose', 64);
                $table->string('identity_key_hash', 64)->nullable();
                $table->string('ip_key_hash', 64)->nullable();
                $table->string('scope', 16)->nullable();
                $table->unsignedInteger('retry_after_seconds')->nullable();
                $table->timestamp('available_at')->nullable();
                $table->foreignId('otp_challenge_id')->nullable()->constrained('otp_challenges')->nullOnDelete();
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
            },
            'breakIncompatible' => static function (): void {
                Schema::dropIfExists('otp_abuse_events');
                Schema::create('otp_abuse_events', function (Blueprint $table) {
                    $table->id();
                    $table->string('decision', 32);
                });
            },
            'insertProbe' => static function (): array {
                $identity = hash('sha256', 'probe-abuse-'.Str::uuid());
                DB::table('otp_abuse_events')->insert([
                    'decision' => 'allowed',
                    'purpose' => 'akubica_register',
                    'identity_key_hash' => $identity,
                    'created_at' => now(),
                ]);

                return ['column' => 'identity_key_hash', 'value' => $identity];
            },
        ],
        'akubica_registration_intents' => [
            'path' => 'migrations/2026_07_27_120000_create_akubica_registration_intents_table.php',
            'table' => 'akubica_registration_intents',
            'seedCompatible' => static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('otp_challenge_id')->unique()->constrained('otp_challenges')->restrictOnDelete();
                $table->string('status', 32);
                $table->text('encrypted_payload')->nullable();
                $table->unsignedTinyInteger('payload_version');
                $table->string('email_fingerprint', 64)->index();
                $table->timestamp('expires_at')->index();
                $table->timestamp('consumed_at')->nullable()->index();
                $table->timestamp('invalidated_at')->nullable()->index();
                $table->string('invalidation_reason', 64)->nullable();
                $table->foreignId('superseded_by_id')
                    ->nullable()
                    ->constrained('akubica_registration_intents')
                    ->nullOnDelete();
                $table->timestamps();
                $table->index(['status', 'expires_at'], 'akubica_reg_intents_status_expires_index');
                $table->index(
                    ['email_fingerprint', 'status', 'expires_at'],
                    'akubica_reg_intents_email_fp_status_expires_index'
                );
            },
            'breakIncompatible' => static function (): void {
                Schema::dropIfExists('akubica_registration_intents');
                Schema::create('akubica_registration_intents', function (Blueprint $table) {
                    $table->id();
                    $table->string('status', 32);
                });
            },
            'insertProbe' => static function (): array {
                $challenge = OtpChallenge::factory()->create();
                $fingerprint = hash('sha256', 'probe-intent-'.Str::uuid());
                DB::table('akubica_registration_intents')->insert([
                    'otp_challenge_id' => $challenge->id,
                    'status' => 'pending',
                    'encrypted_payload' => 'ciphertext-probe',
                    'payload_version' => 1,
                    'email_fingerprint' => $fingerprint,
                    'expires_at' => now()->addHour(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['column' => 'email_fingerprint', 'value' => $fingerprint];
            },
        ],
        'otp_delivery_operations' => [
            'path' => 'migrations/2026_07_28_120000_create_otp_delivery_operations_table.php',
            'table' => 'otp_delivery_operations',
            'seedCompatible' => static function (Blueprint $table): void {
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
            },
            'breakIncompatible' => static function (): void {
                Schema::dropIfExists('otp_delivery_operations');
                Schema::create('otp_delivery_operations', function (Blueprint $table) {
                    $table->id();
                    $table->string('operation_key', 64);
                });
            },
            'insertProbe' => static function (): array {
                $operationKey = 'probe-delivery-'.Str::uuid();
                DB::table('otp_delivery_operations')->insert([
                    'operation_key' => $operationKey,
                    'purpose' => 'akubica_register',
                    'status' => 'succeeded',
                    'primary_channel' => 'sms',
                    'fallback_used' => false,
                    'attempt_count' => 1,
                    'correlation_id' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['column' => 'operation_key', 'value' => $operationKey];
            },
        ],
    ];
}

test('akubica create migrations build tables on clean database', function () {
    foreach (array_keys(akubicaCreateMigrationCases()) as $table) {
        expect(Schema::hasTable($table))->toBeTrue("expected table {$table} after migrate");
    }
});

test('akubica create migrations keep compatible existing tables', function (string $key) {
    $cases = akubicaCreateMigrationCases();
    $case = $cases[$key];
    $table = $case['table'];

    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists($table);
    Schema::create($table, $case['seedCompatible']);
    Schema::enableForeignKeyConstraints();

    expect(Schema::hasTable($table))->toBeTrue();

    /** @var object{up: callable} $migration */
    $migration = require database_path($case['path']);
    $migration->up();

    expect(Schema::hasTable($table))->toBeTrue();
})->with(array_keys(akubicaCreateMigrationCases()))->group('migration-drift');

test('akubica create migrations fail clearly on incompatible existing tables', function (string $key) {
    $cases = akubicaCreateMigrationCases();
    $case = $cases[$key];
    $table = $case['table'];

    Schema::disableForeignKeyConstraints();
    ($case['breakIncompatible'])();
    Schema::enableForeignKeyConstraints();

    /** @var object{up: callable} $migration */
    $migration = require database_path($case['path']);

    try {
        $migration->up();
        expect(false)->toBeTrue('expected RuntimeException for incompatible '.$table);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain($table);
        expect($e->getMessage())->toMatch('/Incompatible|missing/i');
    }
})->with(array_keys(akubicaCreateMigrationCases()))->group('migration-drift');

test('akubica create migrations recreate tables when absent', function (string $key) {
    $cases = akubicaCreateMigrationCases();
    $case = $cases[$key];
    $table = $case['table'];

    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists($table);
    Schema::enableForeignKeyConstraints();

    expect(Schema::hasTable($table))->toBeFalse();

    /** @var object{up: callable} $migration */
    $migration = require database_path($case['path']);
    $migration->up();

    expect(Schema::hasTable($table))->toBeTrue();
})->with(array_keys(akubicaCreateMigrationCases()))->group('migration-drift');

test('akubica create migrations down preserves pre-existing compatible tables and rows', function (string $key) {
    $cases = akubicaCreateMigrationCases();
    $case = $cases[$key];
    $table = $case['table'];

    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists($table);
    Schema::create($table, $case['seedCompatible']);
    Schema::enableForeignKeyConstraints();

    $probe = ($case['insertProbe'])();

    expect(DB::table($table)->where($probe['column'], $probe['value'])->exists())->toBeTrue();

    /** @var object{up: callable, down: callable} $migration */
    $migration = require database_path($case['path']);
    $migration->up();
    $migration->down();

    expect(Schema::hasTable($table))->toBeTrue("down() must not drop pre-existing {$table}");
    expect(DB::table($table)->where($probe['column'], $probe['value'])->exists())
        ->toBeTrue("down() must not delete probe row in {$table}");
})->with(array_keys(akubicaCreateMigrationCases()))->group('migration-drift');
