<?php

use App\Services\Otp\Delivery\FakeOtpDeliveryProvider;
use App\Services\Otp\Delivery\OtpDeliveryRequest;
use App\Services\Otp\Delivery\OtpDeliveryResultClass;

test('fake provider records no OTP or destination PII', function () {
    $provider = app(FakeOtpDeliveryProvider::class)->alwaysAccept();
    $provider->send(new OtpDeliveryRequest(
        purpose: 'akubica_register',
        channel: 'sms',
        destinationE164OrEmail: '+525512345678',
        plainCode: '123456',
        correlationId: '00000000-0000-4000-8000-000000000001',
        attemptNumber: 1,
    ));

    expect($provider->sent)->toHaveCount(1)
        ->and($provider->sent[0])->not->toHaveKey('plainCode')
        ->and($provider->sent[0])->not->toHaveKey('destinationE164OrEmail');
});

test('fake provider accepts configured failure sequence', function () {
    $provider = app(FakeOtpDeliveryProvider::class)->sequence([
        OtpDeliveryResultClass::Timeout,
        OtpDeliveryResultClass::Accepted,
    ]);
    $request = new OtpDeliveryRequest('akubica_register', 'sms', '+525512345678', '123456', '00000000-0000-4000-8000-000000000001', 1);

    expect($provider->send($request)->resultClass)->toBe(OtpDeliveryResultClass::Timeout)
        ->and($provider->send($request)->resultClass)->toBe(OtpDeliveryResultClass::Accepted);
});
