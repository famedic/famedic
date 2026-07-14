<?php

use App\Services\Zoho\SalesIqWebhookService;
use App\Support\ZohoSalesIqWebhookPayloadSanitizer;
use Illuminate\Http\Request;

function zohoResolvePayloadService(): SalesIqWebhookService
{
    return new SalesIqWebhookService(new ZohoSalesIqWebhookPayloadSanitizer);
}

test('resolvePayload keeps application/json request bag', function () {
    $body = [
        'visitor_id' => 'abc123',
        'conversation_id' => 'conv123',
        'intent' => 'help_payment',
        'environment' => 'staging',
    ];

    $request = Request::create(
        '/webhooks/zoho/salesiq/handoff',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($body, JSON_THROW_ON_ERROR),
    );

    $resolved = zohoResolvePayloadService()->resolvePayload($request);

    expect($resolved)->toMatchArray($body);
});

test('resolvePayload decodes raw json without content-type', function () {
    $body = [
        'visitor_id' => 'abc123',
        'conversation_id' => 'conv123',
        'operator_name' => 'Lydia',
        'department' => 'Atención a Clientes',
        'intent' => 'help_payment',
        'last_event' => 'payment_failed',
        'page' => '/laboratory/olab/checkout',
        'environment' => 'staging',
    ];

    $request = Request::create(
        '/webhooks/zoho/salesiq/handoff',
        'POST',
        [],
        [],
        [],
        [],
        json_encode($body, JSON_THROW_ON_ERROR),
    );

    expect($request->all())->toBe([]);

    $resolved = zohoResolvePayloadService()->resolvePayload($request);

    expect($resolved)->toMatchArray($body);
});

test('resolvePayload decodes text/plain json body', function () {
    $body = [
        'visitor_id' => 'plain-visitor',
        'intent' => 'help_cart',
        'environment' => 'staging',
    ];

    $request = Request::create(
        '/webhooks/zoho/salesiq/events',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        json_encode($body, JSON_THROW_ON_ERROR),
    );

    $resolved = zohoResolvePayloadService()->resolvePayload($request);

    expect($resolved)->toMatchArray($body);
});

test('resolvePayload keeps form params when body is not json', function () {
    $form = [
        'visitor_id' => 'form-visitor',
        'conversation_id' => 'form-conv',
        'operator_name' => 'Lydia',
        'department' => 'Atención a Clientes',
        'intent' => 'help_payment',
        'last_event' => 'payment_failed',
        'page' => '/laboratory/olab/checkout',
        'environment' => 'staging',
    ];

    $request = Request::create(
        '/webhooks/zoho/salesiq/handoff',
        'POST',
        $form,
        [],
        [],
        ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
    );

    $resolved = zohoResolvePayloadService()->resolvePayload($request);

    expect($resolved)->toMatchArray($form);
});

test('resolvePayload merges incomplete request bag with json body', function () {
    $request = Request::create(
        '/webhooks/zoho/salesiq/handoff?source=portal',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        json_encode([
            'visitor_id' => 'abc123',
            'intent' => 'help_payment',
            'environment' => 'staging',
        ], JSON_THROW_ON_ERROR),
    );

    // Query param stays available via all(); JSON fills missing fields.
    $resolved = zohoResolvePayloadService()->resolvePayload($request);

    expect($resolved['source'] ?? null)->toBe('portal')
        ->and($resolved['visitor_id'] ?? null)->toBe('abc123')
        ->and($resolved['intent'] ?? null)->toBe('help_payment')
        ->and($resolved['environment'] ?? null)->toBe('staging');
});

test('recordFromRequest sanitizes whitelist and never stores secrets', function () {
    $request = Request::create(
        '/webhooks/zoho/salesiq/handoff',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        json_encode([
            'visitor_id' => 'abc123',
            'conversation_id' => 'conv123',
            'operator_name' => 'Lydia',
            'department' => 'Atención a Clientes',
            'intent' => 'help_payment',
            'last_event' => 'payment_failed',
            'page' => '/laboratory/olab/checkout',
            'environment' => 'staging',
            'password' => 'secret-password',
            'token' => 'secret-token',
            'otp' => '123456',
            'raw_response' => ['x' => 1],
        ], JSON_THROW_ON_ERROR),
    );

    $service = zohoResolvePayloadService();
    $payload = $service->resolvePayload($request);
    $sanitized = (new ZohoSalesIqWebhookPayloadSanitizer)->sanitize(array_merge($payload, [
        'event_type' => 'handoff',
    ]));

    expect($sanitized)->toHaveKey('visitor_id')
        ->and($sanitized)->toHaveKey('conversation_id')
        ->and($sanitized)->toHaveKey('operator_name')
        ->and($sanitized)->toHaveKey('department')
        ->and($sanitized)->toHaveKey('intent')
        ->and($sanitized)->toHaveKey('last_event')
        ->and($sanitized)->toHaveKey('page')
        ->and($sanitized)->toHaveKey('environment')
        ->and($sanitized)->not->toHaveKey('password')
        ->and($sanitized)->not->toHaveKey('token')
        ->and($sanitized)->not->toHaveKey('otp')
        ->and($sanitized)->not->toHaveKey('raw_response');
});
