<?php

use App\Enums\P0aOtpPurpose;
use App\Services\Otp\OtpAbuseKeyHasher;
use App\Services\Otp\OtpRateLimitDecision;

test('hmac identity hash is deterministic and purpose-isolated', function () {
    $hasher = new OtpAbuseKeyHasher;

    $a = $hasher->hashIdentity(
        purpose: P0aOtpPurpose::AkubicaLogin,
        subjectType: 'email',
        subjectKey: 'User@Example.com',
    );
    $b = $hasher->hashIdentity(
        purpose: P0aOtpPurpose::AkubicaLogin,
        subjectType: 'email',
        subjectKey: 'user@example.com',
    );
    $otherPurpose = $hasher->hashIdentity(
        purpose: P0aOtpPurpose::AkubicaRegister,
        subjectType: 'email',
        subjectKey: 'user@example.com',
    );

    expect($a)->toBe($b)
        ->and($a)->toHaveLength(64)
        ->and($a)->not->toBe($otherPurpose)
        ->and($a)->not->toContain('user@example.com');
});

test('ipv4 and ipv6 are normalized before hashing', function () {
    $hasher = new OtpAbuseKeyHasher;

    expect($hasher->normalizeIp('192.168.1.10'))->toBe('192.168.1.10')
        ->and($hasher->normalizeIp(' 192.168.1.10 '))->toBe('192.168.1.10');

    $v6a = $hasher->normalizeIp('2001:db8::1');
    $v6b = $hasher->normalizeIp('2001:0db8:0000:0000:0000:0000:0000:0001');
    expect($v6a)->not->toBeNull()
        ->and($v6a)->toBe($v6b);

    $hashA = $hasher->hashIp('2001:db8::1');
    $hashB = $hasher->hashIp('2001:0db8:0000:0000:0000:0000:0000:0001');
    expect($hashA)->toBe($hashB)
        ->and($hashA)->not->toContain('2001')
        ->and($hasher->hashIp(null))->toBeNull()
        ->and($hasher->hashIp(''))->toBeNull()
        ->and($hasher->hashIp('not-an-ip'))->toBeNull();
});

test('rate limit decision public array never exposes secrets', function () {
    $decision = OtpRateLimitDecision::deny(
        errorCode: OtpRateLimitDecision::CODE_COOLDOWN,
        publicMessage: 'Espera unos segundos antes de solicitar otro codigo.',
        decision: 'cooldown',
        scope: OtpRateLimitDecision::SCOPE_IDENTITY,
        retryAfterSeconds: 42,
        availableAt: now()->addSeconds(42),
        purpose: 'akubica_login',
    );

    $json = json_encode($decision->toPublicArray());

    expect($decision->allowed)->toBeFalse()
        ->and($decision->errorCode)->toBe('OTP_COOLDOWN')
        ->and($json)->not->toContain('code_hash')
        ->and($json)->not->toContain('192.168')
        ->and($json)->not->toContain('@');
});
