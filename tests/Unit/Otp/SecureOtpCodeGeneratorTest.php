<?php

use App\Services\Otp\SecureOtpCodeGenerator;
use Tests\Support\Otp\FakeOtpCodeGenerator;

test('secure generator returns zero-padded digits of requested length', function () {
    $generator = new SecureOtpCodeGenerator;

    $code = $generator->generate(6);

    expect($code)->toHaveLength(6)
        ->and($code)->toMatch('/^\d{6}$/');
});

test('fake generator returns fixed padded code', function () {
    $generator = new FakeOtpCodeGenerator('001234');

    expect($generator->generate(6))->toBe('001234')
        ->and($generator->generate(4))->toBe('1234');
});

test('p0a purpose and channel enum values are stable', function () {
    expect(\App\Enums\P0aOtpPurpose::AkubicaLogin->value)->toBe('akubica_login')
        ->and(\App\Enums\P0aOtpPurpose::AkubicaRegister->value)->toBe('akubica_register')
        ->and(\App\Enums\P0aOtpPurpose::StepUpResults->value)->toBe('step_up_results')
        ->and(\App\Enums\P0aOtpPurpose::StepUpInvoices->value)->toBe('step_up_invoices')
        ->and(\App\Enums\P0aOtpChannel::Sms->value)->toBe('sms')
        ->and(\App\Enums\P0aOtpChannel::Email->value)->toBe('email');
});

test('otp code generator is bound to secure implementation', function () {
    $resolved = app(\App\Contracts\Otp\OtpCodeGenerator::class);

    expect($resolved)->toBeInstanceOf(\App\Services\Otp\SecureOtpCodeGenerator::class);
});
