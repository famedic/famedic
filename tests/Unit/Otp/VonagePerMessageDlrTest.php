<?php

use App\Services\Otp\Delivery\VonageSmsPerMessageCallbackApplicator;
use App\Services\Otp\Delivery\VonageSmsSendResponseParser;
use App\Services\Otp\Delivery\VonageSmsWebhookUrl;
use Vonage\SMS\Collection;
use Vonage\SMS\Message\SMS;

const PER_MESSAGE_DLR_TEST_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789ab';

function enablePerMessageDlrConfig(string $baseUrl = 'https://staging.example.test'): void
{
    config([
        'app.url' => 'http://localhost:8080',
        'vonage.sms_dlr.enabled' => true,
        'vonage.sms_dlr.webhook_token' => PER_MESSAGE_DLR_TEST_TOKEN,
        'vonage.sms_dlr.callback_mode' => 'per_message',
        'vonage.sms_dlr.callback_base_url' => $baseUrl,
    ]);
}

test('sdk 4.11.2 SMS expone setDeliveryReceiptCallback y serializa callback en toArray', function () {
    enablePerMessageDlrConfig();

    $sms = new SMS('525512345678', 'Famedic', 'Tu codigo es 000000');
    $result = VonageSmsPerMessageCallbackApplicator::apply($sms);

    expect($result->applied)->toBeTrue();
    expect(method_exists($sms, 'setDeliveryReceiptCallback'))->toBeTrue();
    expect(method_exists($sms, 'setCallback'))->toBeFalse();

    $payload = $sms->toArray();
    expect($payload)->toHaveKeys(['callback', 'status-report-req', 'to', 'from', 'text']);
    expect($payload['callback'])->toStartWith('https://staging.example.test/webhooks/vonage/sms/delivery/');
    expect($payload['callback'])->toContain('/webhooks/vonage/sms/delivery/');
    expect($payload['status-report-req'])->toBe(1);
    expect(VonageSmsWebhookUrl::redactedPath())->not->toContain(PER_MESSAGE_DLR_TEST_TOKEN);
});

test('callback url usa callback_base_url https aunque APP_URL sea http', function () {
    enablePerMessageDlrConfig('https://staging.famedic.test');

    $url = VonageSmsWebhookUrl::deliveryReceiptCallback();

    expect($url)->toStartWith('https://staging.famedic.test/');
    expect($url)->toContain('/webhooks/vonage/sms/delivery/');
});

test('callback url rechaza APP_URL http cuando no hay callback_base_url', function () {
    config([
        'app.url' => 'http://localhost:8080',
        'vonage.sms_dlr.enabled' => true,
        'vonage.sms_dlr.webhook_token' => PER_MESSAGE_DLR_TEST_TOKEN,
        'vonage.sms_dlr.callback_mode' => 'per_message',
        'vonage.sms_dlr.callback_base_url' => null,
    ]);

    expect(VonageSmsWebhookUrl::deliveryReceiptCallback())->toBeNull();
});

test('callback url no expone token en path redacted helper', function () {
    enablePerMessageDlrConfig();
    expect(VonageSmsWebhookUrl::redactedPath())->toBe('/webhooks/vonage/sms/delivery/[REDACTED]');
    expect(VonageSmsWebhookUrl::redactedPath())->not->toContain(PER_MESSAGE_DLR_TEST_TOKEN);
});

test('parser acepta status 0 y extrae message-id del fixture sdk real', function () {
    $response = new Collection([
        'message-count' => 1,
        'messages' => [[
            'to' => '525512345678',
            'message-id' => '0A000000ABCDEF12',
            'status' => 0,
            'remaining-balance' => '12.34',
            'message-price' => '0.04500',
            'network' => '334020',
        ]],
    ]);

    $parsed = VonageSmsSendResponseParser::parse($response);

    expect($parsed->accepted)->toBeTrue();
    expect($parsed->vonageStatus)->toBe(0);
    expect($parsed->messageId)->toBe('0A000000ABCDEF12');
});

test('parser rechaza status distinto de cero aunque haya message-id', function () {
    $response = new Collection([
        'message-count' => 1,
        'messages' => [[
            'to' => '525512345678',
            'message-id' => '00000000',
            'status' => 3,
            'error-text' => 'Invalid parameter',
            'remaining-balance' => '12.34',
            'message-price' => '0.04500',
            'network' => '334020',
        ]],
    ]);

    $parsed = VonageSmsSendResponseParser::parse($response);

    expect($parsed->accepted)->toBeFalse();
    expect($parsed->vonageStatus)->toBe(3);
    expect($parsed->errorText)->toBe('Invalid parameter');
});

test('dlr desactivado no agrega callback al payload', function () {
    config(['vonage.sms_dlr.enabled' => false]);

    $sms = new SMS('525512345678', 'Famedic', 'test');
    $result = VonageSmsPerMessageCallbackApplicator::apply($sms);

    expect($result->applied)->toBeFalse();
    expect($result->skipReason)->toBe('dlr_disabled');
    expect($sms->toArray())->not->toHaveKey('callback');
});

test('politica de fallback exige error-text con callback para status 3', function () {
    $callback = new \App\Services\Otp\Delivery\VonageSmsCallbackApplyResult(
        applied: true,
        callbackHost: 'staging.example.test',
        callbackUrlLength: 120,
    );
    $parsed = VonageSmsSendResponseParser::parse(new Collection([
        'message-count' => 1,
        'messages' => [[
            'to' => '525512345678',
            'status' => 3,
            'error-text' => 'Invalid callback url',
        ]],
    ]));

    expect(\App\Services\Otp\Delivery\VonageSmsCallbackFallbackPolicy::allowsFallbackWithoutCallback($callback, $parsed))->toBeTrue();
});

test('audit staging callback url sin exponer token', function () {
    config([
        'vonage.sms_dlr.enabled' => true,
        'vonage.sms_dlr.webhook_token' => PER_MESSAGE_DLR_TEST_TOKEN,
        'vonage.sms_dlr.callback_mode' => 'per_message',
        'vonage.sms_dlr.callback_base_url' => 'https://staging.famedic.com.mx',
    ]);

    $audit = \App\Services\Otp\Delivery\VonageSmsWebhookUrl::auditCallbackUrl();

    expect($audit['valid'])->toBeTrue()
        ->and($audit['scheme'])->toBe('https')
        ->and($audit['host'])->toBe('staging.famedic.com.mx')
        ->and($audit['path_redacted'])->toBe('/webhooks/vonage/sms/delivery/[REDACTED]')
        ->and($audit['token_meets_minimum'])->toBeTrue()
        ->and($audit['url_length'])->toBeGreaterThan(0)
        ->and($audit['url_length'])->toBeLessThanOrEqual(200);
});
