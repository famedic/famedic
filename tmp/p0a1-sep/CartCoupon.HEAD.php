<?php

use App\Enums\LaboratoryBrand;
use App\Models\AkubicaCheckoutLink;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\User;

// ── Auth ────────────────────────────────────────────────────────────────

test('GET /cart/coupon without token returns 401', function () {
    $this->getJson('/api/v1/cart/coupon?brand=olab')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('POST /cart/coupon without token returns 401', function () {
    $this->postJson('/api/v1/cart/coupon', ['brand' => 'olab', 'code' => 'PROMO10'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('DELETE /cart/coupon without token returns 401', function () {
    $this->deleteJson('/api/v1/cart/coupon?brand=olab')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('cart coupon endpoints return 403 for user without customer', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;
    $headers = authHeaders($token);

    $this->getJson('/api/v1/cart/coupon?brand=olab', $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $this->postJson('/api/v1/cart/coupon', ['brand' => 'olab', 'code' => 'X'], $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $this->deleteJson('/api/v1/cart/coupon?brand=olab', [], $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

// ── Validación ──────────────────────────────────────────────────────────

test('GET /cart/coupon without brand returns 422', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/cart/coupon', authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('POST /cart/coupon without code returns 422', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/cart/coupon', ['brand' => 'olab'], authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('POST /cart/coupon with invalid brand returns 422', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'marca-invalida',
        'code' => 'PROMO10',
    ], authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

// ── Show ────────────────────────────────────────────────────────────────

test('GET /cart/coupon returns null coupon when none applied', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    $this->getJson('/api/v1/cart/coupon?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'brand' => 'olab',
                'coupon' => null,
            ],
        ]);
});

test('GET /cart/coupon returns applied coupon structure', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))->assertOk();

    $this->getJson('/api/v1/cart/coupon?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.brand', 'olab')
        ->assertJsonPath('data.coupon.code', 'PROMO10')
        ->assertJsonPath('data.coupon.type', 'balance')
        ->assertJsonPath('data.coupon.value', 7000)
        ->assertJsonPath('data.coupon.discount_cents', 7000);
});

// ── Apply ───────────────────────────────────────────────────────────────

test('POST /cart/coupon with empty cart returns 409 EMPTY_CART', function () {
    [$user, $token] = akubicaCustomerToken();
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EMPTY_CART');
});

test('POST /cart/coupon with nonexistent code returns 404 COUPON_NOT_FOUND', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'NOEXISTE',
    ], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'COUPON_NOT_FOUND');
});

test('POST /cart/coupon with used coupon returns 409 COUPON_EXPIRED', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    $coupon = createBalanceCouponForUser($user, 'USADO', 7000);
    CouponUser::query()
        ->where('coupon_id', $coupon->id)
        ->where('user_id', $user->id)
        ->update(['used_at' => now()]);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'USADO',
    ], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COUPON_EXPIRED');
});

test('POST /cart/coupon with zero balance returns 409 COUPON_EXPIRED', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    createBalanceCouponForUser($user, 'SINSALDO', 0);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'SINSALDO',
    ], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COUPON_EXPIRED');
});

test('POST /cart/coupon with balance greater than cart total returns 409 COUPON_NOT_APPLICABLE', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    createBalanceCouponForUser($user, 'GRANDE', 50000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'GRANDE',
    ], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'COUPON_NOT_APPLICABLE');
});

test('POST /cart/coupon with valid coupon returns coupon and totals', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.brand', 'olab')
        ->assertJsonPath('data.coupon.code', 'PROMO10')
        ->assertJsonPath('data.coupon.type', 'balance')
        ->assertJsonPath('data.coupon.discount_cents', 7000)
        ->assertJsonPath('data.totals.subtotal_cents', 45000)
        ->assertJsonPath('data.totals.discount_cents', 10000)
        ->assertJsonPath('data.totals.coupon_discount_cents', 7000)
        ->assertJsonPath('data.totals.total_cents', 28000);
});

test('POST /cart/coupon updates GET /cart/totals', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))->assertOk();

    $this->getJson('/api/v1/cart/totals?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.coupon.code', 'PROMO10')
        ->assertJsonPath('data.coupon_discount_cents', 7000)
        ->assertJsonPath('data.total_cents', 28000);
});

test('POST /cart/coupon updates GET /checkout/prepare', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))->assertOk();

    $this->getJson('/api/v1/checkout/prepare?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.coupon.code', 'PROMO10')
        ->assertJsonPath('data.cart.totals.coupon_discount_cents', 7000)
        ->assertJsonPath('data.cart.totals.total_cents', 28000);
});

test('applying coupon does not affect another brand cart', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    $swissTest = \App\Models\LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::SWISSLAB,
        'famedic_price_cents' => 20000,
        'public_price_cents' => 25000,
    ]);
    \App\Models\LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $swissTest->id,
    ]);

    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))->assertOk();

    $this->getJson('/api/v1/cart/coupon?brand=swisslab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.coupon', null);

    $this->getJson('/api/v1/cart/totals?brand=swisslab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.coupon', null)
        ->assertJsonPath('data.coupon_discount_cents', 0)
        ->assertJsonPath('data.total_cents', 20000);
});

test('applying coupon does not affect another customer', function () {
    [$userA, $tokenA] = akubicaCustomerToken();
    [$userB, $tokenB] = akubicaCustomerToken();

    addOlabCartItem($userA);
    addOlabCartItem($userB);
    createBalanceCouponForUser($userA, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($tokenA))->assertOk();

    $this->getJson('/api/v1/cart/coupon?brand=olab', authHeaders($tokenB))
        ->assertOk()
        ->assertJsonPath('data.coupon', null);
});

// ── Remove ──────────────────────────────────────────────────────────────

test('DELETE /cart/coupon without applied coupon returns removed false', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    $this->deleteJson('/api/v1/cart/coupon?brand=olab', [], authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'brand' => 'olab',
                'removed' => false,
                'coupon' => null,
            ],
        ]);
});

test('DELETE /cart/coupon with applied coupon returns removed true', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))->assertOk();

    $this->deleteJson('/api/v1/cart/coupon?brand=olab', [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.removed', true)
        ->assertJsonPath('data.coupon', null);
});

test('DELETE /cart/coupon recalculates totals without discount', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))->assertOk();

    $this->deleteJson('/api/v1/cart/coupon?brand=olab', [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.totals.coupon_discount_cents', 0)
        ->assertJsonPath('data.totals.discount_cents', 10000)
        ->assertJsonPath('data.totals.total_cents', 35000);
});

test('DELETE /cart/coupon does not affect another brand', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))->assertOk();

    $this->deleteJson('/api/v1/cart/coupon?brand=swisslab', [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.removed', false);

    $this->getJson('/api/v1/cart/coupon?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.coupon.code', 'PROMO10');
});

test('DELETE /cart/coupon does not affect another customer', function () {
    [$userA, $tokenA] = akubicaCustomerToken();
    [$userB, $tokenB] = akubicaCustomerToken();

    addOlabCartItem($userA);
    createBalanceCouponForUser($userA, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($tokenA))->assertOk();

    $this->deleteJson('/api/v1/cart/coupon?brand=olab', [], authHeaders($tokenB))
        ->assertOk()
        ->assertJsonPath('data.removed', false);

    $this->getJson('/api/v1/cart/coupon?brand=olab', authHeaders($tokenA))
        ->assertOk()
        ->assertJsonPath('data.coupon.code', 'PROMO10');
});

// ── Regresión ───────────────────────────────────────────────────────────

test('payment link works with applied coupon', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    setupAkubicaCheckoutDraft($user);
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))->assertOk();

    $this->postJson('/api/v1/checkout/payment-link', [
        'brand' => 'olab',
    ], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'payment_link' => ['url', 'expires_at', 'expires_in_seconds', 'brand', 'is_ready'],
            ],
        ]);

    expect(AkubicaCheckoutLink::query()->count())->toBe(1);
    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::OLAB)
        ->value('coupon_id'))->not->toBeNull();
});

test('payment link does not create purchase or payment', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    setupAkubicaCheckoutDraft($user);

    $purchasesBefore = LaboratoryPurchase::query()->count();

    $this->postJson('/api/v1/checkout/payment-link', [
        'brand' => 'olab',
    ], authHeaders($token))->assertOk();

    expect(LaboratoryPurchase::query()->count())->toBe($purchasesBefore);
});

test('medications catalog endpoint still returns 503', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/medications/1', authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'CATALOG_UNAVAILABLE');
});

test('order cancellation still returns 503', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->putJson('/api/v1/orders/1/cancel', [], authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');
});
