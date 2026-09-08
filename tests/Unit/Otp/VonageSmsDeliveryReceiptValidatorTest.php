<?php

use App\Services\Otp\Delivery\VonageSmsDeliveryReceiptValidator;
use Vonage\Client\Signature;

test('validador usa algoritmo oficial del sdk vonage md5hash', function () {
    config([
        'vonage.sms_dlr.signature_secret' => 'test-signature-secret',
        'vonage.sms_dlr.signature_method' => 'md5hash',
    ]);

    $params = [
        'messageId' => 'audit-msg-1',
        'status' => 'delivered',
        'err-code' => '0',
    ];

    $signed = (new Signature($params, 'test-signature-secret', 'md5hash'))->getSignedParams();

    $validator = app(VonageSmsDeliveryReceiptValidator::class);
    expect($validator->validateSignature($signed))->toBeTrue();
});

test('validador rechaza firma ausente cuando secret configurado', function () {
    config([
        'vonage.sms_dlr.signature_secret' => 'test-signature-secret',
        'vonage.sms_dlr.signature_method' => 'md5hash',
    ]);

    $validator = app(VonageSmsDeliveryReceiptValidator::class);
    expect($validator->validateSignature([
        'messageId' => 'audit-msg-2',
        'status' => 'delivered',
    ]))->toBeFalse();
});

test('validador soporta sha256 hmac cuando configurado', function () {
    config([
        'vonage.sms_dlr.signature_secret' => 'hmac-secret-key',
        'vonage.sms_dlr.signature_method' => 'sha256',
    ]);

    $params = ['messageId' => 'hmac-msg-1', 'status' => 'delivered', 'err-code' => '0'];
    $signed = (new Signature($params, 'hmac-secret-key', 'sha256'))->getSignedParams();

    expect(app(VonageSmsDeliveryReceiptValidator::class)->validateSignature($signed))->toBeTrue();
});

test('token webhook requiere al menos 32 caracteres', function () {
    $validator = app(VonageSmsDeliveryReceiptValidator::class);

    config(['vonage.sms_dlr.webhook_token' => str_repeat('a', 31)]);
    expect($validator->validateRouteToken(str_repeat('a', 31)))->toBeFalse();

    config(['vonage.sms_dlr.webhook_token' => str_repeat('b', 32)]);
    expect($validator->validateRouteToken(str_repeat('b', 32)))->toBeTrue();
});
