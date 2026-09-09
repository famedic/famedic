<?php

use App\Services\Otp\Delivery\OtpDeliveryResultClass;
use App\Services\Otp\Delivery\VonageSmsCallbackApplyResult;
use App\Services\Otp\Delivery\VonageSmsCallbackFallbackPolicy;
use App\Services\Otp\Delivery\VonageSmsSendParseResult;
use Tests\Support\Otp\FakeVonageSmsSendGateway;
use Tests\Support\Otp\makeVonageOtpProvider;
use Tests\Support\Otp\vonageOtpDeliveryRequest;
use Tests\Support\Otp\vonageSmsCollection;

require_once __DIR__.'/../../Support/Otp/FakeVonageSmsSendGateway.php';

const SECURITY_DLR_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789ab';

function enableSecurityPerMessageDlr(): void
{
    config([
        'vonage.sms_dlr.enabled' => true,
        'vonage.sms_dlr.webhook_token' => SECURITY_DLR_TOKEN,
        'vonage.sms_dlr.callback_mode' => 'per_message',
        'vonage.sms_dlr.callback_base_url' => 'https://staging.example.test',
    ]);
}

test('timeout despues de enviar no produce segundo intento', function () {
    enableSecurityPerMessageDlr();
    $gateway = new FakeVonageSmsSendGateway();
    $gateway->pushConnectException();
    $provider = makeVonageOtpProvider($gateway);

    $result = $provider->send(vonageOtpDeliveryRequest());

    expect($gateway->calls)->toBe(1)
        ->and($result->resultClass)->toBe(OtpDeliveryResultClass::TransportError);
});

test('excepcion de transporte no produce segundo intento', function () {
    enableSecurityPerMessageDlr();
    $gateway = new FakeVonageSmsSendGateway();
    $gateway->push(static fn (): never => throw new \RuntimeException('transport failure'));
    $provider = makeVonageOtpProvider($gateway);

    $result = $provider->send(vonageOtpDeliveryRequest());

    expect($gateway->calls)->toBe(1)
        ->and($result->resultClass)->toBe(OtpDeliveryResultClass::TransportError);
});

test('respuesta no interpretable no produce segundo intento', function () {
    enableSecurityPerMessageDlr();
    $gateway = new FakeVonageSmsSendGateway();
    $gateway->pushCollection(new \Vonage\SMS\Collection([]));
    $provider = makeVonageOtpProvider($gateway);

    $result = $provider->send(vonageOtpDeliveryRequest());

    expect($gateway->calls)->toBe(1)
        ->and($result->resultClass)->toBe(OtpDeliveryResultClass::InvalidProviderResponse);
});

test('status 3 sin evidencia de callback no hace fallback', function () {
    enableSecurityPerMessageDlr();
    $gateway = new FakeVonageSmsSendGateway();
    $gateway->pushCollection(vonageSmsCollection(3, 'Invalid parameter'));
    $provider = makeVonageOtpProvider($gateway);

    $result = $provider->send(vonageOtpDeliveryRequest());

    expect($gateway->calls)->toBe(1)
        ->and($result->resultClass)->toBe(OtpDeliveryResultClass::ProviderPermanentFailure);
});

test('rechazo determinístico atribuible al callback hace un unico fallback sin callback', function () {
    enableSecurityPerMessageDlr();
    $gateway = new FakeVonageSmsSendGateway();
    $gateway->pushCollection(vonageSmsCollection(3, 'Invalid callback url'));
    $gateway->push(static function (\Vonage\SMS\Message\SMS $sms, int $call): \Vonage\SMS\Collection {
        expect($call)->toBe(2);
        expect($sms->toArray())->not->toHaveKey('callback');

        return vonageSmsCollection(0, messageId: 'ACCEPTED01');
    });
    $provider = makeVonageOtpProvider($gateway);

    $result = $provider->send(vonageOtpDeliveryRequest());

    expect($gateway->calls)->toBe(2)
        ->and($result->resultClass)->toBe(OtpDeliveryResultClass::Accepted)
        ->and($result->providerMessageId)->toBe('ACCEPTED01');
});

test('primer intento status 0 nunca hace fallback', function () {
    enableSecurityPerMessageDlr();
    $gateway = new FakeVonageSmsSendGateway();
    $gateway->pushCollection(vonageSmsCollection(0, messageId: 'FIRST00001'));
    $provider = makeVonageOtpProvider($gateway);

    $result = $provider->send(vonageOtpDeliveryRequest());

    expect($gateway->calls)->toBe(1)
        ->and($result->resultClass)->toBe(OtpDeliveryResultClass::Accepted);
});

test('politica exige callback en error-text para status 2 y 3', function () {
    $callback = new VonageSmsCallbackApplyResult(applied: true, callbackHost: 'staging.example.test', callbackUrlLength: 120);
    $withEvidence = new VonageSmsSendParseResult(false, null, 3, 'Invalid callback url');
    $withoutEvidence = new VonageSmsSendParseResult(false, null, 3, 'Invalid parameter');

    expect(VonageSmsCallbackFallbackPolicy::allowsFallbackWithoutCallback($callback, $withEvidence))->toBeTrue()
        ->and(VonageSmsCallbackFallbackPolicy::allowsFallbackWithoutCallback($callback, $withoutEvidence))->toBeFalse();
});

test('politica rechaza fallback cuando primer intento fue aceptado', function () {
    $callback = new VonageSmsCallbackApplyResult(applied: true, callbackHost: 'staging.example.test', callbackUrlLength: 120);
    $accepted = new VonageSmsSendParseResult(true, 'msg-1', 0, null);

    expect(VonageSmsCallbackFallbackPolicy::allowsFallbackWithoutCallback($callback, $accepted))->toBeFalse();
});
