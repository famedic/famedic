<?php

use App\Services\Otp\Delivery\VonageSmsSendResponseParser;

use function Tests\Support\Otp\vonageSmsCollection;

require_once __DIR__.'/../../../Support/Otp/FakeVonageSmsSendGateway.php';

test('parser treats vonage status zero as accepted and returns message id', function () {
    $parsed = VonageSmsSendResponseParser::parse(vonageSmsCollection(
        status: 0,
        messageId: 'abcdef1234567890',
    ));

    expect($parsed->accepted)->toBeTrue()
        ->and($parsed->interpretable)->toBeTrue()
        ->and($parsed->vonageStatus)->toBe(0)
        ->and($parsed->messageId)->toBe('abcdef1234567890');
});
