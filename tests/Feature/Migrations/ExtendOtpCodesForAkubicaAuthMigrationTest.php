<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('fresh migrate includes extended otp_codes columns and index', function () {
    expect(Schema::hasTable('otp_codes'))->toBeTrue();

    foreach (['email', 'purpose', 'payload', 'max_attempts', 'used_at', 'ip_address', 'user_agent'] as $column) {
        expect(Schema::hasColumn('otp_codes', $column))->toBeTrue("missing column: {$column}");
    }

    $indexNames = collect(Schema::getIndexes('otp_codes'))->pluck('name')->all();
    expect($indexNames)->toContain('otp_codes_email_purpose_status_index');
});

test('extend otp_codes migration is idempotent when schema already migrated', function () {
    /** @var object{up: callable, down?: callable} $migration */
    $migration = require database_path('migrations/2026_06_10_100000_extend_otp_codes_for_akubica_auth.php');

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('otp_codes', 'purpose'))->toBeTrue();
});

test('extend otp_codes migration adds missing columns on drifted sqlite shape', function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('otp_codes');

    Schema::create('otp_codes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->unsignedBigInteger('laboratory_purchase_id')->nullable();
        $table->string('email')->nullable();
        $table->string('purpose', 32)->default('lab_results');
        $table->string('code');
        $table->string('status')->default('pending');
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->timestamp('verified_at')->nullable();
        $table->timestamps();
    });
    Schema::enableForeignKeyConstraints();

    /** @var object{up: callable} $migration */
    $migration = require database_path('migrations/2026_06_10_100000_extend_otp_codes_for_akubica_auth.php');
    $migration->up();

    foreach (['payload', 'max_attempts', 'used_at', 'ip_address', 'user_agent'] as $column) {
        expect(Schema::hasColumn('otp_codes', $column))->toBeTrue("missing column: {$column}");
    }

    $indexNames = collect(Schema::getIndexes('otp_codes'))->pluck('name')->all();
    expect($indexNames)->toContain('otp_codes_email_purpose_status_index');
})->group('migration-drift');

test('extend otp_codes down preserves legacy purpose email and their data', function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('otp_codes');

    Schema::create('otp_codes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->unsignedBigInteger('laboratory_purchase_id')->nullable();
        $table->string('email')->nullable();
        $table->string('purpose', 32)->default('lab_results');
        $table->string('code');
        $table->string('status')->default('pending');
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->timestamp('verified_at')->nullable();
        $table->timestamps();
    });
    Schema::enableForeignKeyConstraints();

    $id = DB::table('otp_codes')->insertGetId([
        'email' => 'legacy@example.test',
        'purpose' => 'lab_results',
        'code' => '123456',
        'status' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var object{up: callable, down: callable} $migration */
    $migration = require database_path('migrations/2026_06_10_100000_extend_otp_codes_for_akubica_auth.php');
    $migration->up();
    $migration->down();

    expect(Schema::hasColumn('otp_codes', 'email'))->toBeTrue();
    expect(Schema::hasColumn('otp_codes', 'purpose'))->toBeTrue();

    $row = DB::table('otp_codes')->where('id', $id)->first();
    expect($row)->not->toBeNull();
    expect($row->email)->toBe('legacy@example.test');
    expect($row->purpose)->toBe('lab_results');
})->group('migration-drift');

test('extend otp_codes down preserves challenge_id and legacy purpose index', function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('otp_codes');

    Schema::create('otp_codes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->unsignedBigInteger('laboratory_purchase_id')->nullable();
        $table->unsignedBigInteger('challenge_id')->nullable();
        $table->string('email')->nullable();
        $table->string('purpose', 32)->default('lab_results');
        $table->string('code');
        $table->string('status')->default('pending');
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->timestamp('verified_at')->nullable();
        $table->timestamps();

        $table->index(
            ['user_id', 'purpose', 'challenge_id', 'status'],
            'otp_codes_user_purpose_challenge_status'
        );
    });
    Schema::enableForeignKeyConstraints();

    /** @var object{up: callable, down: callable} $migration */
    $migration = require database_path('migrations/2026_06_10_100000_extend_otp_codes_for_akubica_auth.php');
    $migration->up();
    $migration->down();

    expect(Schema::hasColumn('otp_codes', 'challenge_id'))->toBeTrue();
    $indexNames = collect(Schema::getIndexes('otp_codes'))->pluck('name')->all();
    expect($indexNames)->toContain('otp_codes_user_purpose_challenge_status');
})->group('migration-drift');

test('extend otp_codes down does not drop email purpose index without durable authorship', function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('otp_codes');

    Schema::create('otp_codes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->unsignedBigInteger('laboratory_purchase_id')->nullable();
        $table->string('email')->nullable();
        $table->string('purpose', 32)->nullable();
        $table->string('code');
        $table->string('status')->default('pending');
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->timestamp('verified_at')->nullable();
        $table->timestamps();

        $table->index(['email', 'purpose', 'status'], 'otp_codes_email_purpose_status_index');
    });
    Schema::enableForeignKeyConstraints();

    /** @var object{up: callable, down: callable} $migration */
    $migration = require database_path('migrations/2026_06_10_100000_extend_otp_codes_for_akubica_auth.php');
    $migration->up();
    $migration->down();

    $indexNames = collect(Schema::getIndexes('otp_codes'))->pluck('name')->all();
    expect($indexNames)->toContain('otp_codes_email_purpose_status_index');
})->group('migration-drift');

test('extend otp_codes up completes only missing pieces on partial compatible schema', function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('otp_codes');

    Schema::create('otp_codes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->unsignedBigInteger('laboratory_purchase_id')->nullable();
        $table->string('email')->nullable();
        $table->string('purpose', 32)->default('lab_results');
        $table->string('code');
        $table->string('status')->default('pending');
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->timestamp('verified_at')->nullable();
        $table->timestamps();
    });
    Schema::enableForeignKeyConstraints();

    DB::table('otp_codes')->insert([
        'email' => 'keep@example.test',
        'purpose' => 'lab_results',
        'code' => '999999',
        'status' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var object{up: callable} $migration */
    $migration = require database_path('migrations/2026_06_10_100000_extend_otp_codes_for_akubica_auth.php');
    $migration->up();

    foreach (['payload', 'max_attempts', 'used_at', 'ip_address', 'user_agent'] as $column) {
        expect(Schema::hasColumn('otp_codes', $column))->toBeTrue("missing column: {$column}");
    }

    $row = DB::table('otp_codes')->where('email', 'keep@example.test')->first();
    expect($row->purpose)->toBe('lab_results');
    expect($row->code)->toBe('999999');
})->group('migration-drift');
