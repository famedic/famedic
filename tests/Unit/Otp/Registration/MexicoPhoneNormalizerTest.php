<?php

use App\Exceptions\Otp\OtpIdentityNormalizationException;
use App\Services\Otp\Registration\MexicoPhoneNormalizer;
use App\Services\Otp\Registration\PhoneIdentity;

test('mexico phone normalizer accepts equivalent national formats', function (string $input, ?string $country) {
    $phone = app(MexicoPhoneNormalizer::class)->normalize($input, $country);

    expect($phone->countryCode())->toBe('MX')
        ->and($phone->nationalNumber())->toBe('5512345678')
        ->and($phone->e164())->toBe('+525512345678')
        ->and($phone->comparisonKey())->toBe('MX|5512345678')
        ->and((string) $phone)->toBe('[phone-identity]');
})->with([
    ['5512345678', 'MX'],
    ['55 1234 5678', 'MX'],
    ['(55)1234-5678', 'MX'],
    ['+525512345678', null],
    ['+52 55 1234 5678', 'MX'],
    ['525512345678', 'MX'],
]);

test('mexico phone normalizer maps conservative +521 legacy trunk', function () {
    $phone = app(MexicoPhoneNormalizer::class)->normalize('+5215512345678', 'MX');

    expect($phone->nationalNumber())->toBe('5512345678')
        ->and($phone->e164())->toBe('+525512345678')
        ->and($phone->comparisonKey())->toBe('MX|5512345678');
});

test('mexico phone normalizer rejects invalid or ambiguous inputs', function (string $input, ?string $country) {
    expect(fn () => app(MexicoPhoneNormalizer::class)->normalize($input, $country))
        ->toThrow(OtpIdentityNormalizationException::class);
})->with([
    ['', 'MX'],
    ['123', 'MX'],
    ['551234567890123', 'MX'],
    ['55ABCDEFGH', 'MX'],
    ['5512345678 ext 99', 'MX'],
    ['+1 2125551234', 'US'],
    ['+573001234567', 'CO'],
]);

test('phone identity equals by comparison key', function () {
    $a = new PhoneIdentity('MX', '5512345678', '+525512345678', 'MX|5512345678');
    $b = new PhoneIdentity('MX', '5512345678', '+525512345678', 'MX|5512345678');

    expect($a->equals($b))->toBeTrue();
});
