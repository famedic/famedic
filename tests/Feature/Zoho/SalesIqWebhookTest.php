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

function zohoHandoffPayload(): array
{
    return [
        'visitor_id' => 'abc123',
        'conversation_id' => 'conv123',
        'operator_name' => 'Lydia',
        'department' => 'Atención a Clientes',
        'intent' => 'help_payment',
        'last_event' => 'payment_failed',
        'page' => '/laboratory/olab/checkout',
        'environment' => 'staging',
    ];
}

/**
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function zohoWebhookServer(array $extra = []): array
{
    return array_merge([
        'HTTP_X_FAMEDIC_ZOHO_SECRET' => 'test-zoho-webhook-secret',
        'HTTP_ACCEPT' => 'application/json',
    ], $extra);
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

test('zoho salesiq events webhook stores sanitized general event with application/json', function () {
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
        ->and($event->payload)->toHaveKey('intent')
        ->and($event->payload)->not->toHaveKey('secret');
});

test('zoho salesiq handoff webhook stores fields from raw json without content-type', function () {
    $content = json_encode(zohoHandoffPayload(), JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        route('webhooks.zoho.salesiq.handoff'),
        [],
        [],
        [],
        zohoWebhookServer([
            'CONTENT_LENGTH' => (string) strlen($content),
        ]),
        $content,
    )->assertOk()->assertJson(['ok' => true]);

    $event = ZohoSalesIqEvent::query()->first();

    expect($event)->not->toBeNull()
        ->and($event->event_type)->toBe('handoff')
        ->and($event->visitor_id)->toBe('abc123')
        ->and($event->conversation_id)->toBe('conv123')
        ->and($event->operator_name)->toBe('Lydia')
        ->and($event->department)->toBe('Atención a Clientes')
        ->and($event->intent)->toBe('help_payment')
        ->and($event->last_event)->toBe('payment_failed')
        ->and($event->page)->toBe('/laboratory/olab/checkout')
        ->and($event->environment)->toBe('staging')
        ->and($event->payload['visitor_id'] ?? null)->toBe('abc123')
        ->and($event->payload['event_type'] ?? null)->toBe('handoff');
});

test('zoho salesiq handoff webhook stores fields from text/plain json body', function () {
    $content = json_encode(zohoHandoffPayload(), JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        route('webhooks.zoho.salesiq.handoff'),
        [],
        [],
        [],
        zohoWebhookServer([
            'CONTENT_TYPE' => 'text/plain',
            'CONTENT_LENGTH' => (string) strlen($content),
        ]),
        $content,
    )->assertOk()->assertJson(['ok' => true]);

    $event = ZohoSalesIqEvent::query()->first();

    expect($event->event_type)->toBe('handoff')
        ->and($event->visitor_id)->toBe('abc123')
        ->and($event->operator_name)->toBe('Lydia')
        ->and($event->department)->toBe('Atención a Clientes')
        ->and($event->environment)->toBe('staging');
});

test('zoho salesiq handoff webhook stores fields from form params', function () {
    $this->withHeaders(zohoWebhookHeaders())
        ->post(route('webhooks.zoho.salesiq.handoff'), zohoHandoffPayload())
        ->assertOk()
        ->assertJson(['ok' => true]);

    $event = ZohoSalesIqEvent::query()->first();

    expect($event->event_type)->toBe('handoff')
        ->and($event->visitor_id)->toBe('abc123')
        ->and($event->conversation_id)->toBe('conv123')
        ->and($event->operator_name)->toBe('Lydia')
        ->and($event->department)->toBe('Atención a Clientes')
        ->and($event->intent)->toBe('help_payment')
        ->and($event->last_event)->toBe('payment_failed')
        ->and($event->page)->toBe('/laboratory/olab/checkout')
        ->and($event->environment)->toBe('staging');
});

test('zoho salesiq handoff webhook stores handoff event with application/json', function () {
    $this->postJson(
        route('webhooks.zoho.salesiq.handoff'),
        zohoHandoffPayload(),
        zohoWebhookHeaders(),
    )->assertOk()->assertJson(['ok' => true]);

    $event = ZohoSalesIqEvent::query()->first();

    expect($event->event_type)->toBe('handoff')
        ->and($event->visitor_id)->toBe('abc123')
        ->and($event->conversation_id)->toBe('conv123')
        ->and($event->operator_name)->toBe('Lydia')
        ->and($event->department)->toBe('Atención a Clientes')
        ->and($event->intent)->toBe('help_payment')
        ->and($event->last_event)->toBe('payment_failed')
        ->and($event->page)->toBe('/laboratory/olab/checkout')
        ->and($event->environment)->toBe('staging');
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
            'environment' => 'staging',
            'last_event' => 'cart_viewed',
        ],
        zohoWebhookHeaders(),
    )->assertOk()->assertJson(['ok' => true]);

    $event = ZohoSalesIqEvent::query()->first();

    expect($event->event_type)->toBe('conversation_closed')
        ->and($event->visitor_id)->toBe('abc123')
        ->and($event->conversation_id)->toBe('conv123')
        ->and($event->operator_name)->toBe('Lydia')
        ->and($event->department)->toBe('Atención a Clientes')
        ->and($event->intent)->toBe('help_cart')
        ->and($event->last_event)->toBe('cart_viewed')
        ->and($event->page)->toBe('/laboratory/olab/shopping-cart')
        ->and($event->environment)->toBe('staging')
        ->and($event->payload['resolution'] ?? null)->toBe('resolved');
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
        'webhook_secret' => 'should-not-pass-whitelist',
    ]);

    expect($result)->toBe([
        'intent' => 'help_payment',
        'cart_total_cents' => 239729,
    ])
        ->and($result)->not->toHaveKey('password')
        ->and($result)->not->toHaveKey('token')
        ->and($result)->not->toHaveKey('otp')
        ->and($result)->not->toHaveKey('webhook_secret')
        ->and($result)->not->toHaveKey('nested');
});

test('zoho salesiq webhook payload sanitizer keeps flat result_ids lists', function () {
    $sanitizer = new ZohoSalesIqWebhookPayloadSanitizer;

    $result = $sanitizer->sanitize([
        'query' => 'colesterol bueno',
        'result_count' => 2,
        'result_ids' => [10, 20],
        'handoff_recommended' => true,
        'reason' => 'breve',
        'nested_object' => ['a' => 1],
    ]);

    expect($result['query'])->toBe('colesterol bueno')
        ->and($result['result_count'])->toBe(2)
        ->and($result['result_ids'])->toBe([10, 20])
        ->and($result['handoff_recommended'])->toBeTrue()
        ->and($result['reason'])->toBe('breve')
        ->and($result)->not->toHaveKey('nested_object');
});

test('zoho salesiq webhook payload sanitizer keeps study search counters', function () {
    $sanitizer = new ZohoSalesIqWebhookPayloadSanitizer;

    $result = $sanitizer->sanitize([
        'query' => 'orina',
        'brand' => 'unknown',
        'state' => 'Ciudad de México',
        'store_count' => 2,
        'result_count' => 1,
        'result_ids' => [10],
    ]);

    expect($result['state'] ?? null)->toBe('Ciudad de México')
        ->and($result['store_count'] ?? null)->toBe(2);
});

test('zoho salesiq raw json body is sanitized and never stores secrets', function () {
    $content = json_encode([
        'event_type' => 'bot_intent',
        'visitor_id' => 'visitor-raw',
        'conversation_id' => 'conv-raw',
        'intent' => 'help_payment',
        'last_event' => 'payment_failed',
        'page' => '/laboratory/olab/checkout',
        'environment' => 'staging',
        'password' => 'secret-password',
        'token' => 'secret-token',
        'otp' => '999999',
        'card_number' => '4111111111111111',
        'raw_response' => ['gateway' => true],
    ], JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        route('webhooks.zoho.salesiq.events'),
        [],
        [],
        [],
        zohoWebhookServer([
            'CONTENT_TYPE' => 'text/plain',
            'CONTENT_LENGTH' => (string) strlen($content),
        ]),
        $content,
    )->assertOk();

    $event = ZohoSalesIqEvent::query()->first();

    expect($event->visitor_id)->toBe('visitor-raw')
        ->and($event->conversation_id)->toBe('conv-raw')
        ->and($event->intent)->toBe('help_payment')
        ->and($event->payload)->not->toHaveKey('password')
        ->and($event->payload)->not->toHaveKey('token')
        ->and($event->payload)->not->toHaveKey('otp')
        ->and($event->payload)->not->toHaveKey('card_number')
        ->and($event->payload)->not->toHaveKey('raw_response')
        ->and(json_encode($event->payload))->not->toContain('secret-password')
        ->and(json_encode($event->payload))->not->toContain('secret-token');
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
