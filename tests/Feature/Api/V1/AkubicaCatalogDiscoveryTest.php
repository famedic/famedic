<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;

// ── Catálogo público (sin Bearer token) ───────────────────────────────

test('GET /catalog/laboratory-brands without token returns 200', function () {
    $this->getJson('/api/v1/catalog/laboratory-brands')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(count(LaboratoryBrand::cases()), 'data.brands');
});

test('GET /catalog/laboratory-test-categories without token returns 200', function () {
    $category = LaboratoryTestCategory::factory()->create(['name' => 'Hematología']);
    createOlabTest(['laboratory_test_category_id' => $category->id]);

    $this->getJson('/api/v1/catalog/laboratory-test-categories?brand=olab')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.categories');
});

test('GET /catalog/laboratory-tests without token returns 200', function () {
    createOlabTest(['name' => 'Glucosa en ayunas']);

    $this->getJson('/api/v1/catalog/laboratory-tests?brand=olab')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.laboratory_tests');
});

test('GET /catalog/laboratory-tests with search without token returns 200', function () {
    createOlabTest(['name' => 'Glucosa en ayunas']);
    createOlabTest(['name' => 'Perfil lipídico']);

    $this->getJson('/api/v1/catalog/laboratory-tests?brand=olab&search=glucosa')
        ->assertOk()
        ->assertJsonCount(1, 'data.laboratory_tests')
        ->assertJsonPath('data.laboratory_tests.0.name', 'Glucosa en ayunas');
});

test('GET /catalog/laboratory-tests with category_id without token returns 200', function () {
    $hematology = LaboratoryTestCategory::factory()->create(['name' => 'Hematología']);
    $chemistry = LaboratoryTestCategory::factory()->create(['name' => 'Química']);

    createOlabTest([
        'name' => 'Biometría',
        'laboratory_test_category_id' => $hematology->id,
    ]);

    createOlabTest([
        'name' => 'Perfil lipídico',
        'laboratory_test_category_id' => $chemistry->id,
    ]);

    $this->getJson("/api/v1/catalog/laboratory-tests?brand=olab&category_id={$hematology->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.laboratory_tests')
        ->assertJsonPath('data.laboratory_tests.0.name', 'Biometría');
});

test('GET /catalog/laboratory-tests with pagination without token returns 200', function () {
    for ($i = 0; $i < 25; $i++) {
        createOlabTest(['name' => "Estudio paginado {$i}"]);
    }

    $this->getJson('/api/v1/catalog/laboratory-tests?brand=olab&page=2&per_page=20')
        ->assertOk()
        ->assertJsonPath('data.pagination.current_page', 2)
        ->assertJsonPath('data.pagination.per_page', 20)
        ->assertJsonPath('data.pagination.total', 25)
        ->assertJsonCount(5, 'data.laboratory_tests');
});

test('GET /catalog/laboratory-stores without token returns 200', function () {
    LaboratoryStore::query()->create([
        'name' => 'Olab San Pedro',
        'brand' => LaboratoryBrand::OLAB,
        'state' => 'Nuevo León',
        'address' => 'Av. Ejemplo 123',
        'weekly_hours' => 'L-V 8:00-18:00',
        'saturday_hours' => '8:00-14:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example.com',
    ]);

    $this->getJson('/api/v1/catalog/laboratory-stores?brand=olab')
        ->assertOk()
        ->assertJsonCount(1, 'data.stores');
});

test('GET /catalog/laboratory-tests/{id} without token returns 200', function () {
    $test = createOlabTest(['name' => 'Detalle público']);

    $this->getJson("/api/v1/catalog/laboratory-tests/{$test->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $test->id)
        ->assertJsonPath('data.name', 'Detalle público');
});

test('POST /cart/items without token returns 401 UNAUTHENTICATED', function () {
    $test = createOlabTest();

    $this->postJson('/api/v1/cart/items', [
        'brand' => 'olab',
        'laboratory_test_id' => $test->id,
    ])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /checkout/prepare without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/checkout/prepare?brand=olab')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /orders without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/orders')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

// ── Brands ────────────────────────────────────────────────────────────

test('GET /catalog/laboratory-brands returns brands structure', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/laboratory-brands', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonCount(count(LaboratoryBrand::cases()), 'data.brands')
        ->assertJsonStructure([
            'data' => [
                'brands' => [[
                    'id',
                    'name',
                    'label',
                    'is_active',
                ]],
            ],
        ])
        ->assertJsonPath('data.brands.0.id', 'olab')
        ->assertJsonPath('data.brands.0.is_active', true);
});

// ── Laboratory tests index ────────────────────────────────────────────

test('GET /catalog/laboratory-tests with no tests returns empty list', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/laboratory-tests', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'laboratory_tests' => [],
                'pagination' => [
                    'current_page' => 1,
                    'total' => 0,
                ],
            ],
        ]);
});

test('GET /catalog/laboratory-tests returns tests with expected structure', function () {
    [, $token] = akubicaCustomerToken();
    $category = LaboratoryTestCategory::factory()->create(['name' => 'Hematología']);

    createOlabTest([
        'name' => 'Biometría hemática',
        'indications' => 'Ayuno de 8 horas',
        'laboratory_test_category_id' => $category->id,
        'requires_appointment' => false,
    ]);

    $this->getJson('/api/v1/catalog/laboratory-tests?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.laboratory_tests')
        ->assertJsonPath('data.laboratory_tests.0.name', 'Biometría hemática')
        ->assertJsonPath('data.laboratory_tests.0.brand', 'olab')
        ->assertJsonPath('data.laboratory_tests.0.price_cents', 35000)
        ->assertJsonPath('data.laboratory_tests.0.currency', 'MXN')
        ->assertJsonPath('data.laboratory_tests.0.requires_appointment', false)
        ->assertJsonPath('data.laboratory_tests.0.category.id', $category->id)
        ->assertJsonPath('data.laboratory_tests.0.category.name', 'Hematología')
        ->assertJsonPath('data.laboratory_tests.0.indications', 'Ayuno de 8 horas')
        ->assertJsonPath('data.laboratory_tests.0.is_available', true)
        ->assertJsonMissingPath('data.laboratory_tests.0.gda_id');
});

test('GET /catalog/laboratory-tests filters by search text', function () {
    [, $token] = akubicaCustomerToken();

    createOlabTest(['name' => 'Biometría hemática completa']);
    createOlabTest(['name' => 'Química sanguínea']);

    $this->getJson('/api/v1/catalog/laboratory-tests?search=completa', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.laboratory_tests')
        ->assertJsonPath('data.laboratory_tests.0.name', 'Biometría hemática completa');
});

test('GET /catalog/laboratory-tests filters by brand', function () {
    [, $token] = akubicaCustomerToken();

    createOlabTest(['name' => 'Estudio Olab']);
    LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::SWISSLAB,
        'name' => 'Estudio Swisslab',
    ]);

    $this->getJson('/api/v1/catalog/laboratory-tests?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.laboratory_tests')
        ->assertJsonPath('data.laboratory_tests.0.brand', 'olab');
});

test('GET /catalog/laboratory-tests filters by category_id', function () {
    [, $token] = akubicaCustomerToken();

    $hematology = LaboratoryTestCategory::factory()->create(['name' => 'Hematología']);
    $chemistry = LaboratoryTestCategory::factory()->create(['name' => 'Química']);

    createOlabTest([
        'name' => 'Biometría',
        'laboratory_test_category_id' => $hematology->id,
    ]);

    createOlabTest([
        'name' => 'Perfil lipídico',
        'laboratory_test_category_id' => $chemistry->id,
    ]);

    $this->getJson("/api/v1/catalog/laboratory-tests?category_id={$hematology->id}", authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.laboratory_tests')
        ->assertJsonPath('data.laboratory_tests.0.name', 'Biometría');
});

test('GET /catalog/laboratory-tests with invalid per_page returns 422', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/laboratory-tests?per_page=1000', authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('GET /catalog/laboratory-tests paginates correctly', function () {
    [, $token] = akubicaCustomerToken();

    for ($i = 0; $i < 3; $i++) {
        createOlabTest(['name' => "Estudio {$i}"]);
    }

    $this->getJson('/api/v1/catalog/laboratory-tests?per_page=2&page=2', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.pagination.current_page', 2)
        ->assertJsonPath('data.pagination.per_page', 2)
        ->assertJsonPath('data.pagination.total', 3)
        ->assertJsonCount(1, 'data.laboratory_tests');
});

test('GET /catalog/laboratory-tests filters by requires_appointment', function () {
    [, $token] = akubicaCustomerToken();

    createOlabTest(['name' => 'Sin cita', 'requires_appointment' => false]);
    createOlabTest(['name' => 'Con cita', 'requires_appointment' => true]);

    $this->getJson('/api/v1/catalog/laboratory-tests?requires_appointment=1', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.laboratory_tests')
        ->assertJsonPath('data.laboratory_tests.0.name', 'Con cita')
        ->assertJsonPath('data.laboratory_tests.0.requires_appointment', true);
});

// ── Categories ────────────────────────────────────────────────────────

test('GET /catalog/laboratory-test-categories returns categories structure', function () {
    [, $token] = akubicaCustomerToken();

    $category = LaboratoryTestCategory::factory()->create(['name' => 'Hematología']);
    createOlabTest(['laboratory_test_category_id' => $category->id]);

    $this->getJson('/api/v1/catalog/laboratory-test-categories?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.categories')
        ->assertJsonPath('data.categories.0.id', $category->id)
        ->assertJsonPath('data.categories.0.name', 'Hematología')
        ->assertJsonPath('data.categories.0.slug', 'hematologia');
});

test('GET /catalog/laboratory-test-categories with no categories returns empty array', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/laboratory-test-categories', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => ['categories' => []],
        ]);
});

// ── Stores ────────────────────────────────────────────────────────────

test('GET /catalog/laboratory-stores returns stores structure', function () {
    [, $token] = akubicaCustomerToken();

    LaboratoryStore::query()->create([
        'name' => 'Olab San Pedro',
        'brand' => LaboratoryBrand::OLAB,
        'state' => 'Nuevo León',
        'address' => 'Av. Ejemplo 123',
        'weekly_hours' => 'L-V 8:00-18:00',
        'saturday_hours' => '8:00-14:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example.com',
    ]);

    $this->getJson('/api/v1/catalog/laboratory-stores?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.stores')
        ->assertJsonPath('data.stores.0.name', 'Olab San Pedro')
        ->assertJsonPath('data.stores.0.brand', 'olab')
        ->assertJsonPath('data.stores.0.state', 'Nuevo León')
        ->assertJsonPath('data.stores.0.is_active', true);
});

test('GET /catalog/laboratory-stores filters by brand', function () {
    [, $token] = akubicaCustomerToken();

    LaboratoryStore::query()->create([
        'name' => 'Olab Centro',
        'brand' => LaboratoryBrand::OLAB,
        'state' => 'Nuevo León',
        'address' => 'Calle 1',
        'weekly_hours' => '9-17',
        'saturday_hours' => '9-13',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example.com/1',
    ]);

    LaboratoryStore::query()->create([
        'name' => 'Swisslab Centro',
        'brand' => LaboratoryBrand::SWISSLAB,
        'state' => 'Nuevo León',
        'address' => 'Calle 2',
        'weekly_hours' => '9-17',
        'saturday_hours' => '9-13',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example.com/2',
    ]);

    $this->getJson('/api/v1/catalog/laboratory-stores?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.stores')
        ->assertJsonPath('data.stores.0.brand', 'olab');
});

// ── Regresión ─────────────────────────────────────────────────────────

test('GET /catalog/laboratory-tests/{id} still returns laboratory test detail', function () {
    [, $token] = akubicaCustomerToken();
    $test = createOlabTest(['name' => 'Detalle estudio']);

    $this->getJson("/api/v1/catalog/laboratory-tests/{$test->id}", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.id', $test->id)
        ->assertJsonPath('data.name', 'Detalle estudio')
        ->assertJsonStructure(['data' => ['gda_id', 'available']]);
});

test('GET /catalog/medications/{id} still returns 503 CATALOG_UNAVAILABLE', function () {
    $this->getJson('/api/v1/catalog/medications/1')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'CATALOG_UNAVAILABLE');
});

test('PUT /orders/{order_id}/cancel still returns 503 FEATURE_DISABLED', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $this->putJson("/api/v1/orders/{$order->id}/cancel", [], authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');
});
