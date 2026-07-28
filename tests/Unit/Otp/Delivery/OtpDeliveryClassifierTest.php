<?php

use App\Services\Otp\Delivery\OtpDeliveryClassifier;
use App\Services\Otp\Delivery\OtpDeliveryResultClass;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

test('delivery classifier maps transport failures without message matching', function () {
    $classifier = new OtpDeliveryClassifier;

    expect($classifier->classify(new ConnectException('connection failed', new Request('POST', 'https://example.test'))))
        ->toBe(OtpDeliveryResultClass::TransportError)
        ->and($classifier->classify(new RuntimeException, 429))
        ->toBe(OtpDeliveryResultClass::RateLimitedByProvider)
        ->and($classifier->classify(new RuntimeException, 503))
        ->toBe(OtpDeliveryResultClass::ProviderTemporaryFailure)
        ->and($classifier->classify(new RuntimeException, 400))
        ->toBe(OtpDeliveryResultClass::ProviderPermanentFailure);
});
