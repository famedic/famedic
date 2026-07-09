<?php

use App\Models\ZohoSalesIqEvent;
use App\Support\ZohoSalesIqWebhookPayloadSanitizer;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('services.zoho.salesiq.webhook_secret', 'test-zoho-webhook-secret');
});

function zohoWebhookHeaders(string $secret = 'test-zoho-webhook-secret'): array
{
    return [
        'X-Famedic-Zoho-Secret' => $secret,
        'Accept' => 'application/json',
    ];
}

test('zoho salesiq events webhook rejects missing secret', function () {
    $this->postJson(route('webhooks.zoho.salesiq.events'), [
        'event_type' => 'bot_intent',
        'intent' => 'help_payment',
    ])->assertUnauthorized()
        ->assertJson(['ok' => false]);

    expect(ZohoSalesIqEvent::count())->toBe(0);
});

test('zoho salesiq events webhook rejects invalid secret', function () {
    $this->postJson(
        route('webhooks.zoho.salesiq.events'),
        ['event_type' => 'bot_intent'],
        zohoWebhookHeaders('wrong-secret'),
    )->assertUnauthorized();

    expect(ZohoSalesIqEvent::count())->toBe(0);
});

test('zoho salesiq events webhook rejects when secret is not configured', function () {
    Config::set('services.zoho.salesiq.webhook_secret', '');

    $this->postJson(
        route('webhooks.zoho.salesiq.events'),
        ['event_type' => 'bot_intent'],
        zohoWebhookHeaders('anything'),
    )->assertUnauthorized();

    expect(ZohoSalesIqEvent::count())->toBe(0);
});

test('zoho salesiq events webhook stores sanitized general event', function () {
    $response = $this->postJson(
        route('webhooks.zoho.salesiq.events'),
        [
            'event_type' => 'bot_intent',
            'visitor_id' => 'abc123',
            'conversation_id' => 'conv123',
            'intent' => 'help_payment',
            'last_event' => 'payment_failed',
            'page' => '/laboratory/olab/checkout',
            'environment' => 'staging',
            'password' => 'should-not-persist',
            'card_number' => '4111111111111111',
            'otp' => '123456',
            'raw_response' => ['secret' => true],
        ],
        zohoWebhookHeaders(),
    );

    $response->assertOk()->assertJson(['ok' => true]);

    $event = ZohoSalesIqEvent::query()->first();

    expect($event)->not->toBeNull()
        ->and($event->event_type)->toBe('bot_intent')
        ->and($event->visitor_id)->toBe('abc123')
        ->and($event->conversation_id)->toBe('conv123')
        ->and($event->intent)->toBe('help_payment')
        ->and($event->last_event)->toBe('payment_failed')
        ->and($event->page)->toBe('/laboratory/olab/checkout')
        ->and($event->environment)->toBe('staging')
        ->and($event->payload)->not->toHaveKey('password')
        ->and($event->payload)->not->toHaveKey('card_number')
        ->and($event->payload)->not->toHaveKey('otp')
        ->and($event->payload)->not->toHaveKey('raw_response')
        ->and($event->payload)->toHaveKey('intent');
});

test('zoho salesiq handoff webhook stores handoff event', function () {
    $this->postJson(
        route('webhooks.zoho.salesiq.handoff'),
        [
            'visitor_id' => 'abc123',
            'conversation_id' => 'conv123',
            'operator_name' => 'Lydia',
            'department' => 'Atención a Clientes',
            'intent' => 'help_payment',
            'last_event' => 'payment_failed',
            'page' => '/laboratory/olab/checkout',
        ],
        zohoWebhookHeaders(),
    )->assertOk()->assertJson(['ok' => true]);

    $event = ZohoSalesIqEvent::query()->first();

    expect($event->event_type)->toBe('handoff')
        ->and($event->operator_name)->toBe('Lydia')
        ->and($event->department)->toBe('Atención a Clientes')
        ->and($event->intent)->toBe('help_payment');
});

test('zoho salesiq conversation closed webhook stores closure', function () {
    $this->postJson(
        route('webhooks.zoho.salesiq.conversation-closed'),
        [
            'visitor_id' => 'abc123',
            'conversation_id' => 'conv123',
            'operator_name' => 'Lydia',
            'department' => 'Atención a Clientes',
            'intent' => 'help_cart',
            'resolution' => 'resolved',
            'page' => '/laboratory/olab/shopping-cart',
        ],
        zohoWebhookHeaders(),
    )->assertOk()->assertJson(['ok' => true]);

    $event = ZohoSalesIqEvent::query()->first();

    expect($event->event_type)->toBe('conversation_closed')
        ->and($event->payload['resolution'] ?? null)->toBe('resolved')
        ->and($event->intent)->toBe('help_cart');
});

test('zoho salesiq webhook payload sanitizer strips forbidden keys', function () {
    $sanitizer = new ZohoSalesIqWebhookPayloadSanitizer;

    $result = $sanitizer->sanitize([
        'intent' => 'help_payment',
        'password' => 'x',
        'token' => 'y',
        'otp' => '1',
        'card_cvv' => '123',
        'raw_response' => 'nope',
        'cart_total_cents' => 239729,
        'nested' => ['a' => 1],
    ]);

    expect($result)->toBe([
        'intent' => 'help_payment',
        'cart_total_cents' => 239729,
    ]);
});

test('zoho salesiq frontend config does not expose webhook secret', function () {
    Config::set('services.zoho.salesiq.webhook_secret', 'super-secret');
    Config::set('services.zoho.salesiq.enabled', true);
    Config::set('services.zoho.salesiq.widget_code', 'wc-test');

    $config = \App\Support\ZohoSalesIq::frontendConfig();

    expect($config)->not->toHaveKey('webhook_secret')
        ->and($config)->not->toHaveKey('webhookSecret')
        ->and(json_encode($config))->not->toContain('super-secret');
});
