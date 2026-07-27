<?php

use App\Exceptions\Otp\OtpIdentityNormalizationException;
use App\Services\Otp\Registration\EmailNormalizer;
use App\Services\Otp\Registration\NormalizedEmail;

test('email normalizer trims and lowercases without changing plus aliases', function () {
    $n = app(EmailNormalizer::class);

    $a = $n->normalize('  Ana.User+tag@Example.COM ');
    $b = $n->normalize('ana.user+tag@example.com');

    expect($a->value())->toBe('ana.user+tag@example.com')
        ->and($a->equals($b))->toBeTrue()
        ->and($a->comparisonKey())->toBe('ana.user+tag@example.com')
        ->and((string) $a)->toBe('[normalized-email]');
});

test('email normalizer rejects empty and invalid formats', function (string $input) {
    expect(fn () => app(EmailNormalizer::class)->normalize($input))
        ->toThrow(OtpIdentityNormalizationException::class);
})->with([
    '',
    '   ',
    'not-an-email',
    '@ejemplo.com',
    'user@',
]);

test('normalized email object does not leak value via toString', function () {
    $email = new NormalizedEmail('secreto@ejemplo.com');
    expect((string) $email)->not->toContain('secreto')
        ->and((string) $email)->not->toContain('@');
});
