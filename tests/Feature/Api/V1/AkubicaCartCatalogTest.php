<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryTest;
use App\Models\User;

// ── Auth ──────────────────────────────────────────────────────────────

test('GET /cart without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/cart?brand=olab')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /cart with user without customer returns 403 FORBIDDEN', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->getJson('/api/v1/cart?brand=olab', authHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

// ── GET cart ────────────────────────────────────────────────────────

test('GET /cart without brand returns 422 VALIDATION_ERROR', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/cart', authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['fields' => ['brand']]]);
});

test('GET /cart with valid token and empty cart returns empty items', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/cart?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'brand' => 'olab',
                'items' => [],
                'subtotal_cents' => 0,
                'discount_cents' => 0,
                'total_cents' => 0,
            ],
        ]);
});

test('GET /cart returns items and correct totals', function () {
    [$user, $token] = akubicaCustomerToken();
    $test = createOlabTest([
        'famedic_price_cents' => 35000,
        'public_price_cents' => 45000,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    $this->getJson('/api/v1/cart?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.items.0.laboratory_test_id', $test->id)
        ->assertJsonPath('data.items.0.price_cents', 35000)
        ->assertJsonPath('data.subtotal_cents', 45000)
        ->assertJsonPath('data.discount_cents', 10000)
        ->assertJsonPath('data.total_cents', 35000);
});

test('GET /cart filters items by brand', function () {
    [$user, $token] = akubicaCustomerToken();

    $olabTest = createOlabTest(['name' => 'Estudio Olab']);
    $swissTest = LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::SWISSLAB,
        'name' => 'Estudio Swiss',
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $olabTest->id,
    ]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $swissTest->id,
    ]);

    $this->getJson('/api/v1/cart?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.laboratory_test_id', $olabTest->id);
});

// ── POST cart item ──────────────────────────────────────────────────

test('POST /cart/items with invalid body returns 422 VALIDATION_ERROR', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/cart/items', [], authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('POST /cart/items with nonexistent laboratory_test_id returns 404 LAB_TEST_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/cart/items', [
        'laboratory_test_id' => 999999,
        'brand' => 'olab',
    ], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'LAB_TEST_NOT_FOUND');
});

test('POST /cart/items with brand mismatch returns 404 LAB_TEST_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();
    $test = createOlabTest();

    $this->postJson('/api/v1/cart/items', [
        'laboratory_test_id' => $test->id,
        'brand' => 'swisslab',
    ], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'LAB_TEST_NOT_FOUND');
});

test('POST /cart/items with valid laboratory_test_id adds item and returns cart summary', function () {
    [$user, $token] = akubicaCustomerToken();
    $test = createOlabTest([
        'name' => 'Biometría hemática',
        'famedic_price_cents' => 35000,
        'public_price_cents' => 35000,
    ]);

    $this->postJson('/api/v1/cart/items', [
        'laboratory_test_id' => $test->id,
        'brand' => 'olab',
    ], authHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.item.laboratory_test_id', $test->id)
        ->assertJsonPath('data.item.name', 'Biometría hemática')
        ->assertJsonPath('data.cart.total_cents', 35000);

    expect($user->customer->laboratoryCartItems()->count())->toBe(1);
});

test('POST /cart/items duplicate returns 409 ITEM_ALREADY_IN_CART', function () {
    [$user, $token] = akubicaCustomerToken();
    $test = createOlabTest();

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    $this->postJson('/api/v1/cart/items', [
        'laboratory_test_id' => $test->id,
        'brand' => 'olab',
    ], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ITEM_ALREADY_IN_CART');
});

// ── DELETE cart item ────────────────────────────────────────────────

test('DELETE /cart/items/{id} for nonexistent item returns 404 CART_ITEM_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();

    $this->deleteJson('/api/v1/cart/items/999999', [], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CART_ITEM_NOT_FOUND');
});

test('DELETE /cart/items/{id} for another customer returns 403 FORBIDDEN', function () {
    [$owner] = akubicaCustomerToken();
    [, $otherToken] = akubicaCustomerToken();

    $test = createOlabTest();
    $cartItem = LaboratoryCartItem::factory()->create([
        'customer_id' => $owner->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    $this->deleteJson('/api/v1/cart/items/'.$cartItem->id, [], authHeaders($otherToken))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

test('DELETE /cart/items/{id} removes own item and returns updated cart', function () {
    [$user, $token] = akubicaCustomerToken();
    $test = createOlabTest();

    $cartItem = LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    $this->deleteJson('/api/v1/cart/items/'.$cartItem->id, [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.removed_item_id', $cartItem->id)
        ->assertJsonPath('data.cart.items', [])
        ->assertJsonPath('data.cart.total_cents', 0);

    expect(LaboratoryCartItem::query()->find($cartItem->id))->toBeNull();
});

// ── Catalog laboratory test ─────────────────────────────────────────

test('GET /catalog/laboratory-tests/{id} for nonexistent test returns 404 LAB_TEST_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/laboratory-tests/999999', authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'LAB_TEST_NOT_FOUND');
});

test('GET /catalog/laboratory-tests/{id} returns laboratory test data', function () {
    [, $token] = akubicaCustomerToken();
    $test = createOlabTest([
        'name' => 'Biometría hemática',
        'description' => 'Descripción del estudio',
        'gda_id' => 'ABC123',
        'requires_appointment' => false,
    ]);

    $this->getJson('/api/v1/catalog/laboratory-tests/'.$test->id, authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $test->id,
                'name' => 'Biometría hemática',
                'description' => 'Descripción del estudio',
                'brand' => 'olab',
                'price_cents' => 35000,
                'currency' => 'MXN',
                'requires_appointment' => false,
                'gda_id' => 'ABC123',
                'available' => true,
            ],
        ]);
});

test('GET /catalog/medications/{id} returns 503 CATALOG_UNAVAILABLE', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/medications/1', authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'CATALOG_UNAVAILABLE');
});
