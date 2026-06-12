<?php

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryTest;
use App\Models\User;

function createLaboratoryPurchaseForCustomer(Customer $customer, array $attributes = []): LaboratoryPurchase
{
    return LaboratoryPurchase::query()->create(array_merge([
        'customer_id' => $customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'gda_order_id' => 'GDA-'.fake()->unique()->numerify('######'),
        'gda_consecutivo' => fake()->unique()->numberBetween(100000, 999999),
        'name' => 'Juan',
        'paternal_lastname' => 'Pérez',
        'maternal_lastname' => 'López',
        'phone' => '8181234567',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
        'street' => 'Calle Test',
        'number' => '100',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo León',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 35000,
    ], $attributes));
}

// ── Auth ──────────────────────────────────────────────────────────────

test('GET /orders without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/orders')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /orders with user without customer returns 403 FORBIDDEN', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->getJson('/api/v1/orders', authHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

// ── Orders index ──────────────────────────────────────────────────────

test('GET /orders with customer and no orders returns empty array', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/orders', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'orders' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                ],
            ],
        ]);
});

test('GET /orders returns orders with expected structure', function () {
    [$user, $token] = akubicaCustomerToken();

    $purchase = createLaboratoryPurchaseForCustomer($user->customer, [
        'total_cents' => 35000,
    ]);

    LaboratoryPurchaseItem::query()->create([
        'laboratory_purchase_id' => $purchase->id,
        'gda_id' => 'GDA-ITEM-001',
        'name' => 'Biometría hemática',
        'indications' => 'Ayuno de 8 horas',
        'price_cents' => 35000,
    ]);

    $this->getJson('/api/v1/orders', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $purchase->id)
        ->assertJsonPath('data.orders.0.status', 'in_progress')
        ->assertJsonPath('data.orders.0.status_label', 'En proceso')
        ->assertJsonPath('data.orders.0.brand', 'olab')
        ->assertJsonPath('data.orders.0.study_name', 'Biometría hemática')
        ->assertJsonPath('data.orders.0.total_cents', 35000)
        ->assertJsonPath('data.orders.0.currency', 'MXN')
        ->assertJsonPath('data.orders.0.is_cancelled', false)
        ->assertJsonStructure([
            'data' => [
                'orders' => [[
                    'id',
                    'status',
                    'status_label',
                    'brand',
                    'study_name',
                    'total_cents',
                    'currency',
                    'is_cancelled',
                    'created_at',
                    'updated_at',
                ]],
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            ],
        ]);
});

test('GET /orders only returns orders of authenticated customer', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    createLaboratoryPurchaseForCustomer($owner->customer);
    createLaboratoryPurchaseForCustomer($other->customer);

    $this->getJson('/api/v1/orders', authHeaders($ownerToken))
        ->assertOk()
        ->assertJsonCount(1, 'data.orders');
});

test('GET /orders paginates correctly', function () {
    [$user, $token] = akubicaCustomerToken();

    for ($i = 0; $i < 3; $i++) {
        createLaboratoryPurchaseForCustomer($user->customer);
    }

    $this->getJson('/api/v1/orders?per_page=2&page=2', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.pagination.current_page', 2)
        ->assertJsonPath('data.pagination.per_page', 2)
        ->assertJsonPath('data.pagination.total', 3)
        ->assertJsonCount(1, 'data.orders');
});

test('GET /orders filters by status in_progress', function () {
    [$user, $token] = akubicaCustomerToken();

    $inProgress = createLaboratoryPurchaseForCustomer($user->customer);
    $cancelled = createLaboratoryPurchaseForCustomer($user->customer);
    $cancelled->delete();

    $this->getJson('/api/v1/orders?status=in_progress', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $inProgress->id);
});

test('GET /orders filters by brand', function () {
    [$user, $token] = akubicaCustomerToken();

    $olab = createLaboratoryPurchaseForCustomer($user->customer, ['brand' => LaboratoryBrand::OLAB]);
    createLaboratoryPurchaseForCustomer($user->customer, ['brand' => LaboratoryBrand::SWISSLAB]);

    $this->getJson('/api/v1/orders?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.id', $olab->id);
});

// ── Products ──────────────────────────────────────────────────────────

test('GET /orders/{id}/products for nonexistent order returns 404 ORDER_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/orders/999999/products', authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('GET /orders/{id}/products for another customer order returns 404 ORDER_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $otherOrder = createLaboratoryPurchaseForCustomer($other->customer);

    $this->getJson("/api/v1/orders/{$otherOrder->id}/products", authHeaders($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('GET /orders/{id}/products for own order without items returns empty products', function () {
    [$user, $token] = akubicaCustomerToken();

    $purchase = createLaboratoryPurchaseForCustomer($user->customer);

    $this->getJson("/api/v1/orders/{$purchase->id}/products", authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'order_id' => $purchase->id,
                'products' => [],
            ],
        ]);
});

test('GET /orders/{id}/products returns products with expected structure', function () {
    [$user, $token] = akubicaCustomerToken();

    $purchase = createLaboratoryPurchaseForCustomer($user->customer);
    $test = createOlabTest([
        'gda_id' => 'TEST-GDA-001',
        'requires_appointment' => true,
    ]);

    LaboratoryPurchaseItem::query()->create([
        'laboratory_purchase_id' => $purchase->id,
        'gda_id' => $test->gda_id,
        'name' => 'Biometría hemática',
        'indications' => 'Ayuno de 8 horas',
        'price_cents' => 35000,
    ]);

    $this->getJson("/api/v1/orders/{$purchase->id}/products", authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.products')
        ->assertJsonPath('data.order_id', $purchase->id)
        ->assertJsonPath('data.products.0.laboratory_test_id', $test->id)
        ->assertJsonPath('data.products.0.name', 'Biometría hemática')
        ->assertJsonPath('data.products.0.brand', 'olab')
        ->assertJsonPath('data.products.0.price_cents', 35000)
        ->assertJsonPath('data.products.0.currency', 'MXN')
        ->assertJsonPath('data.products.0.quantity', 1)
        ->assertJsonPath('data.products.0.requires_appointment', true);
});

// ── Status ────────────────────────────────────────────────────────────

test('GET /orders/{id}/status for nonexistent order returns 404 ORDER_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/orders/999999/status', authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('GET /orders/{id}/status returns status data for own order', function () {
    [$user, $token] = akubicaCustomerToken();

    $purchase = createLaboratoryPurchaseForCustomer($user->customer);

    $this->getJson("/api/v1/orders/{$purchase->id}/status", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.order_id', $purchase->id)
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.status_label', 'En proceso')
        ->assertJsonPath('data.pipeline', null)
        ->assertJsonPath('data.is_cancelled', false)
        ->assertJsonPath('data.results_available', false)
        ->assertJsonStructure(['data' => ['updated_at']]);
});

test('GET /orders/{id}/status returns cancelled for soft-deleted order', function () {
    [$user, $token] = akubicaCustomerToken();

    $purchase = createLaboratoryPurchaseForCustomer($user->customer);
    $purchase->delete();

    $this->getJson("/api/v1/orders/{$purchase->id}/status", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.status_label', 'Cancelado')
        ->assertJsonPath('data.is_cancelled', true);
});

// ── Regresión 501 ─────────────────────────────────────────────────────

test('PUT /orders/{id}/cancel returns 503 FEATURE_DISABLED', function () {
    [$user, $token] = akubicaCustomerToken();
    $purchase = createLaboratoryPurchaseForCustomer($user->customer);

    $this->putJson("/api/v1/orders/{$purchase->id}/cancel", [], authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');
});

test('GET /catalog/medications/{id} returns 503 CATALOG_UNAVAILABLE', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/medications/1', authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'CATALOG_UNAVAILABLE');
});
