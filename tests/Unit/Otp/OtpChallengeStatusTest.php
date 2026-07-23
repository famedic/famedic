<?php

use App\Models\OtpChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('status precedence is consumed over invalidated over expired over pending', function () {
    $pending = OtpChallenge::factory()->create([
        'expires_at' => now()->addMinutes(5),
    ]);
    expect($pending->status())->toBe(OtpChallenge::STATUS_PENDING)
        ->and($pending->isPending())->toBeTrue();

    $expired = OtpChallenge::factory()->expired()->create();
    expect($expired->status())->toBe(OtpChallenge::STATUS_EXPIRED)
        ->and($expired->isExpired())->toBeTrue()
        ->and($expired->isPending())->toBeFalse();

    $invalidated = OtpChallenge::factory()->invalidated()->create([
        'expires_at' => now()->addMinutes(5),
    ]);
    expect($invalidated->status())->toBe(OtpChallenge::STATUS_INVALIDATED);

    $consumed = OtpChallenge::factory()->consumed()->invalidated()->expired()->create();
    expect($consumed->status())->toBe(OtpChallenge::STATUS_CONSUMED);
});

test('creation result toArray never exposes plain code', function () {
    $this->app->instance(
        \App\Contracts\Otp\OtpCodeGenerator::class,
        new \Tests\Support\Otp\FakeOtpCodeGenerator('001234')
    );

    $service = app(\App\Services\Otp\OtpChallengeService::class);
    $result = $service->create(new \App\Services\Otp\CreateOtpChallengeData(
        purpose: \App\Enums\P0aOtpPurpose::AkubicaLogin,
        channel: \App\Enums\P0aOtpChannel::Email,
        ttlMinutes: 5,
        subjectType: 'email',
        subjectKey: 'user@example.com',
        destinationNormalized: 'user@example.com',
    ));

    $array = $result->toArray();
    $serialized = json_encode($array);

    expect($result->plainCode())->toBe('001234')
        ->and($array)->not->toHaveKey('plainCode')
        ->and($serialized)->not->toContain('001234')
        ->and($result->challenge->toArray())->not->toHaveKey('code_hash');
});
