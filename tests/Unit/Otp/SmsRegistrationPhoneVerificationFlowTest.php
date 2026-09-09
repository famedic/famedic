<?php

use App\Contracts\Otp\OtpCodeGenerator;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\User;
use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\Delivery\OtpDeliveryResultClass;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\Otp\FakeOtpCodeGenerator;

uses(DatabaseTransactions::class);

beforeEach(function () {
    disableAllAkubicaOtpFeatures();
});

test('sms registration verification marks phone verified and eligible login sends exactly one sms', function () {
    enableRegisterOtpWithFakeDelivery();
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('654321'));

    $register = $this->postJson('/api/v1/auth/register', [
        'email' => 'sms.channel.verified@example.test',
        'phone' => '+52 55 1234 6101',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    expect(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1)
        ->and(app(FakeOtpDeliveryProvider::class)->sent[0]['purpose'])->toBe('akubica_register');

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $register->json('data.challenge_id'),
        'code' => '654321',
    ])->assertOk();

    $user = User::query()->where('email', 'sms.channel.verified@example.test')->with('customer')->first();

    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->phone_verified_at)->not->toBeNull()
        ->and($user->customer)->not->toBeNull()
        ->and(OtpDeliveryOperation::query()->where('purpose', 'akubica_register')->count())->toBe(1);

    enableLoginOtpWithFakeDelivery();
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('111111'));

    $login = $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+52 55 1234 6101',
    ])->assertStatus(202);

    expect($login->json('data.destination_masked'))->toBe('***6101')
        ->and(OtpChallenge::query()->where('purpose', 'akubica_login')->count())->toBe(1)
        ->and(OtpDeliveryOperation::query()->where('purpose', 'akubica_login')->count())->toBe(1)
        ->and(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1)
        ->and(app(FakeOtpDeliveryProvider::class)->sent[0]['purpose'])->toBe('akubica_login');
});

test('email fallback registration verification marks email only and login remains decoy', function () {
    enableRegisterOtpWithFakeDelivery();
    config()->set('otp.p0a.flags.email_fallback_enabled', true);
    app()->instance(OtpCodeGenerator::class, new FakeOtpCodeGenerator('777777'));
    app(FakeOtpDeliveryProvider::class)->failOnceWith(OtpDeliveryResultClass::Timeout);

    $register = $this->postJson('/api/v1/auth/register', [
        'email' => 'email.fallback.verified@example.test',
        'phone' => '+52 55 1234 6102',
        'full_name' => 'Nombre Apellido',
    ])->assertStatus(202);

    expect(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(1);

    $this->postJson('/api/v1/auth/register/verify-code', [
        'challenge_id' => $register->json('data.challenge_id'),
        'code' => '777777',
    ])->assertOk();

    $user = User::query()->where('email', 'email.fallback.verified@example.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->phone_verified_at)->toBeNull()
        ->and(OtpDeliveryOperation::query()->where('purpose', 'akubica_register')->first()->fallback_used)->toBeTrue()
        ->and(OtpDeliveryOperation::query()->where('purpose', 'akubica_register')->first()->result_class)->toBe(OtpDeliveryResultClass::FallbackAccepted->value);

    enableLoginOtpWithFakeDelivery();

    $this->postJson('/api/v1/auth/login/request-code', [
        'phone' => '+52 55 1234 6102',
    ])->assertStatus(202);

    expect(OtpChallenge::query()->where('purpose', 'akubica_login')->count())->toBe(0)
        ->and(app(FakeOtpDeliveryProvider::class)->sent)->toHaveCount(0);
});
