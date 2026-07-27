<?php

use App\Models\User;
use App\Services\Otp\Registration\EmailNormalizer;
use App\Services\Otp\Registration\MexicoPhoneNormalizer;
use App\Services\Otp\Registration\RegistrationCollisionKind;
use App\Services\Otp\Registration\RegistrationCollisionResolver;

function p0a52Identity(string $email, string $phone, ?string $country = 'MX'): array
{
    return [
        app(EmailNormalizer::class)->normalize($email),
        app(MexicoPhoneNormalizer::class)->normalize($phone, $country),
    ];
}

test('collision resolver reports available for new contacts', function () {
    [$email, $phone] = p0a52Identity('nuevo.disp@ejemplo.com', '5511110001');

    $result = app(RegistrationCollisionResolver::class)->resolve($email, $phone);

    expect($result->kind)->toBe(RegistrationCollisionKind::Available)
        ->and($result->isAvailable())->toBeTrue();
});

test('collision resolver detects email exists case-insensitively', function () {
    User::factory()->create([
        'email' => 'Existente@Ejemplo.com',
        'phone' => '5511110002',
        'phone_country' => 'MX',
    ]);

    [$email, $phone] = p0a52Identity('existente@ejemplo.com', '5511110099');
    $result = app(RegistrationCollisionResolver::class)->resolve($email, $phone);

    expect($result->kind)->toBe(RegistrationCollisionKind::EmailExists);
});

test('collision resolver detects phone exists across formats', function () {
    User::factory()->create([
        'email' => 'otro@ejemplo.com',
        'phone' => '5511110003',
        'phone_country' => 'MX',
    ]);

    [$email, $phone] = p0a52Identity('fresh@ejemplo.com', '+52 55 1111 0003');
    $result = app(RegistrationCollisionResolver::class)->resolve($email, $phone);

    expect($result->kind)->toBe(RegistrationCollisionKind::PhoneExists);
});

test('collision resolver detects both contacts on same user', function () {
    User::factory()->create([
        'email' => 'mismo@ejemplo.com',
        'phone' => '5511110004',
        'phone_country' => 'MX',
    ]);

    [$email, $phone] = p0a52Identity('mismo@ejemplo.com', '5511110004');
    $result = app(RegistrationCollisionResolver::class)->resolve($email, $phone);

    expect($result->kind)->toBe(RegistrationCollisionKind::BothSameUser);
});

test('collision resolver detects contacts belonging to different users', function () {
    User::factory()->create([
        'email' => 'alpha@ejemplo.com',
        'phone' => '5511110005',
        'phone_country' => 'MX',
    ]);
    User::factory()->create([
        'email' => 'beta@ejemplo.com',
        'phone' => '5511110006',
        'phone_country' => 'MX',
    ]);

    [$email, $phone] = p0a52Identity('alpha@ejemplo.com', '5511110006');
    $result = app(RegistrationCollisionResolver::class)->resolve($email, $phone);

    expect($result->kind)->toBe(RegistrationCollisionKind::ContactsBelongToDifferentUsers);
});

test('collision resolver marks ambiguous phone when historical duplicates exist', function () {
    User::factory()->create([
        'email' => 'dup1@ejemplo.com',
        'phone' => '5511110007',
        'phone_country' => 'MX',
    ]);
    User::factory()->create([
        'email' => 'dup2@ejemplo.com',
        'phone' => '5511110007',
        'phone_country' => 'MX',
    ]);

    [$email, $phone] = p0a52Identity('nuevo.dup@ejemplo.com', '5511110007');
    $result = app(RegistrationCollisionResolver::class)->resolve($email, $phone);

    expect($result->kind)->toBe(RegistrationCollisionKind::AmbiguousPhone)
        ->and($result->kind->shouldUseDecoy())->toBeFalse();
});

test('collision resolver does not create users or tokens', function () {
    $beforeUsers = User::query()->count();
    [$email, $phone] = p0a52Identity('solo.lectura@ejemplo.com', '5511110008');
    app(RegistrationCollisionResolver::class)->resolve($email, $phone);

    expect(User::query()->count())->toBe($beforeUsers)
        ->and(\Laravel\Sanctum\PersonalAccessToken::query()->count())->toBe(0);
});
