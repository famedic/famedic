<?php

use App\Enums\MedicalSubscriptionType;
use App\Models\MedicalAttentionSubscription;
use App\Models\MedicalServiceCart;
use App\Models\MedicalServiceCartItem;
use App\Models\User;
use App\Services\Api\V1\MedicalServiceCartService;
use App\Services\Api\V1\Idempotency\IdempotencyKey;
use App\Support\Api\V1\AkubicaCorrelationId;

function createM2bActiveMembership(User $user, MedicalSubscriptionType $type = MedicalSubscriptionType::REGULAR): void
{
    MedicalAttentionSubscription::query()->create([
        'customer_id' => $user->customer->id,
        'type' => $type,
        'price_cents' => $type === MedicalSubscriptionType::REGULAR ? 30000 : 0,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
    ]);
}

function m2bMedicalHeaders(string $token): array
{
    return array_merge(authHeaders($token), [
        AkubicaCorrelationId::HEADER => 'm2b-correlation-abcdef',
    ]);
}

test('adds the basic plan to a medical services cart with authoritative totals', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/cart/items', [
        'service_line' => 'medical_services',
        'item_type' => 'membership',
        'membership_plan_code' => 'basic_annual',
    ], m2bMedicalHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.service_line', 'medical_services')
        ->assertJsonPath('data.items.0.item_type', 'membership')
        ->assertJsonPath('data.items.0.membership_plan_code', 'basic_annual')
        ->assertJsonPath('data.items.0.quantity', 1)
        ->assertJsonPath('data.items.0.unit_price_cents', 30000)
        ->assertJsonPath('data.items.0.subtotal_cents', 30000)
        ->assertJsonPath('data.totals.subtotal_cents', 30000)
        ->assertJsonPath('data.totals.discount_cents', 0)
        ->assertJsonPath('data.totals.credit_applied_cents', 0)
        ->assertJsonPath('data.totals.total_cents', 30000)
        ->assertJsonPath('data.applied_benefits', []);
});

test('gets medical cart and medical totals without mixing laboratory data', function () {
    [$user, $token] = akubicaCustomerToken();
    app(MedicalServiceCartService::class)->addBasicPlan($user->customer);

    $this->getJson('/api/v1/cart?service_line=medical_services', m2bMedicalHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.service_line', 'medical_services')
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.membership_plan_code', 'basic_annual');

    $this->getJson('/api/v1/cart/totals?service_line=medical_services', m2bMedicalHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.service_line', 'medical_services')
        ->assertJsonPath('data.total_cents', 30000)
        ->assertJsonPath('data.applied_benefits', []);
});

test('deletes a medical membership cart item', function () {
    [$user, $token] = akubicaCustomerToken();
    $cart = app(MedicalServiceCartService::class)->addBasicPlan($user->customer);
    $itemId = $cart->items->sole()->id;

    $this->deleteJson("/api/v1/cart/items/{$itemId}", [], m2bMedicalHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.service_line', 'medical_services')
        ->assertJsonPath('data.totals.total_cents', 0);

    expect(MedicalServiceCartItem::query()->find($itemId))->toBeNull();
});

test('prepares medical checkout without payment or activation side effects', function () {
    [$user, $token] = akubicaCustomerToken();
    app(MedicalServiceCartService::class)->addBasicPlan($user->customer);

    $this->getJson('/api/v1/checkout/prepare?service_line=medical_services', m2bMedicalHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.service_line', 'medical_services')
        ->assertJsonPath('data.cart.totals.total_cents', 30000)
        ->assertJsonPath('data.can_continue_to_payment_platform', false)
        ->assertJsonPath('data.assistant_can_pay', false)
        ->assertJsonPath('data.can_continue_on_platform', true)
        ->assertJsonPath('data.requirements.has_items', true);

    expect($user->customer->medicalAttentionSubscriptions()->count())->toBe(0)
        ->and($user->customer->medicalServiceCarts()->first()->items()->count())->toBe(1);
});

test('blocks adding a plan when an individual membership is active', function () {
    [$user, $token] = akubicaCustomerToken();
    createM2bActiveMembership($user);

    $this->postJson('/api/v1/cart/items', [
        'service_line' => 'medical_services',
        'item_type' => 'membership',
        'membership_plan_code' => 'basic_annual',
    ], m2bMedicalHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'MEMBERSHIP_ALREADY_ACTIVE');
});

test('blocks adding a plan when a sponsored membership is active', function () {
    [$user, $token] = akubicaCustomerToken();
    createM2bActiveMembership($user, MedicalSubscriptionType::INSTITUTIONAL);

    $this->postJson('/api/v1/cart/items', [
        'service_line' => 'medical_services',
        'item_type' => 'membership',
        'membership_plan_code' => 'basic_annual',
    ], m2bMedicalHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SPONSORED_MEMBERSHIP_ACTIVE');
});

test('blocks duplicate membership and invalid service line combinations', function () {
    [$user, $token] = akubicaCustomerToken();
    $payload = [
        'service_line' => 'medical_services',
        'item_type' => 'membership',
        'membership_plan_code' => 'basic_annual',
    ];

    $this->postJson('/api/v1/cart/items', $payload, m2bMedicalHeaders($token))->assertCreated();
    $this->postJson('/api/v1/cart/items', $payload, m2bMedicalHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'MEMBERSHIP_ALREADY_IN_CART');

    $this->postJson('/api/v1/cart/items', [
        ...$payload,
        'brand' => 'olab',
    ], m2bMedicalHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CART_SERVICE_LINE_MISMATCH');
});

test('rejects a medical membership quantity different from one', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/cart/items', [
        'service_line' => 'medical_services',
        'item_type' => 'membership',
        'membership_plan_code' => 'basic_annual',
        'quantity' => 2,
    ], m2bMedicalHeaders($token))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('rejects deleting another customer medical cart item', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [, $otherToken] = akubicaCustomerToken();
    $cart = app(MedicalServiceCartService::class)->addBasicPlan($owner->customer);
    $itemId = $cart->items->sole()->id;

    $this->deleteJson("/api/v1/cart/items/{$itemId}", [], m2bMedicalHeaders($otherToken))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

test('replays adding a medical membership with the same idempotency key', function () {
    config()->set('api_v1.idempotency.enabled', true);
    [$user, $token] = akubicaCustomerToken();
    $headers = array_merge(m2bMedicalHeaders($token), [IdempotencyKey::HEADER => 'm2b-idempotency-abcdef']);
    $payload = [
        'service_line' => 'medical_services',
        'item_type' => 'membership',
        'membership_plan_code' => 'basic_annual',
    ];

    $first = $this->postJson('/api/v1/cart/items', $payload, $headers)->assertCreated();
    $second = $this->postJson('/api/v1/cart/items', $payload, $headers)->assertCreated();

    expect($second->json('data.totals.total_cents'))->toBe(30000)
        ->and($user->customer->medicalServiceCarts()->first()->items()->count())->toBe(1);
});
