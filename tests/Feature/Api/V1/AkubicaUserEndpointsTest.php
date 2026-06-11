<?php

use App\Enums\Gender;
use App\Enums\Kinship;
use App\Models\Address;
use App\Models\EfevooToken;
use App\Models\FamilyAccount;
use App\Models\TaxProfile;
use App\Models\User;

// ── Auth ──────────────────────────────────────────────────────────────

test('GET /user/family without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/user/family')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /user/family with user without customer returns 403 FORBIDDEN', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->getJson('/api/v1/user/family', authHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

// ── Family ────────────────────────────────────────────────────────────

test('GET /user/family with customer and no family returns empty array', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/user/family', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => ['family' => []],
        ]);
});

test('GET /user/family returns family members with expected structure', function () {
    [$user, $token] = akubicaCustomerToken();

    FamilyAccount::factory()->create([
        'customer_id' => $user->customer->id,
        'name' => 'Juan',
        'paternal_lastname' => 'Pérez',
        'maternal_lastname' => 'López',
        'birth_date' => '2015-01-01',
        'gender' => Gender::MALE,
        'kinship' => Kinship::CHILD,
    ]);

    $this->getJson('/api/v1/user/family', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.family')
        ->assertJsonPath('data.family.0.full_name', 'Juan Pérez López')
        ->assertJsonPath('data.family.0.relationship', 'child')
        ->assertJsonPath('data.family.0.birth_date', '2015-01-01')
        ->assertJsonPath('data.family.0.gender', 'male')
        ->assertJsonPath('data.family.0.is_main_profile', false);
});

test('GET /user/family only returns family of authenticated customer', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    FamilyAccount::factory()->create([
        'customer_id' => $owner->customer->id,
        'birth_date' => '2010-05-05',
        'gender' => Gender::FEMALE,
        'kinship' => Kinship::CHILD,
    ]);

    FamilyAccount::factory()->create([
        'customer_id' => $other->customer->id,
        'birth_date' => '2008-03-03',
        'gender' => Gender::MALE,
        'kinship' => Kinship::CHILD,
    ]);

    $this->getJson('/api/v1/user/family', authHeaders($ownerToken))
        ->assertOk()
        ->assertJsonCount(1, 'data.family');
});

// ── Tax profiles ──────────────────────────────────────────────────────

test('GET /user/tax-profiles with no profiles returns empty array', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/user/tax-profiles', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => ['tax_profiles' => []],
        ]);
});

test('GET /user/tax-profiles returns profiles with expected structure', function () {
    [$user, $token] = akubicaCustomerToken();

    TaxProfile::factory()->for($user->customer)->create([
        'name' => 'Perfil principal',
        'razon_social' => 'PUBLICO EN GENERAL',
        'rfc' => 'XAXX010101000',
        'tax_regime' => '616',
        'cfdi_use' => 'S01',
        'zipcode' => '64000',
    ]);

    $this->getJson('/api/v1/user/tax-profiles', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.tax_profiles')
        ->assertJsonPath('data.tax_profiles.0.rfc', 'XAXX010101000')
        ->assertJsonPath('data.tax_profiles.0.business_name', 'PUBLICO EN GENERAL')
        ->assertJsonPath('data.tax_profiles.0.tax_regime', '616')
        ->assertJsonPath('data.tax_profiles.0.cfdi_use', 'S01')
        ->assertJsonPath('data.tax_profiles.0.postal_code', '64000');
});

test('GET /user/tax-profiles only returns profiles of authenticated customer', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    TaxProfile::factory()->for($owner->customer)->create(['rfc' => 'OWNER111111111']);
    TaxProfile::factory()->for($other->customer)->create(['rfc' => 'OTHER222222222']);

    $this->getJson('/api/v1/user/tax-profiles', authHeaders($ownerToken))
        ->assertOk()
        ->assertJsonCount(1, 'data.tax_profiles')
        ->assertJsonPath('data.tax_profiles.0.rfc', 'OWNER111111111');
});

// ── Addresses ─────────────────────────────────────────────────────────

test('GET /user/addresses with no addresses returns empty array', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/user/addresses', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => ['addresses' => []],
        ]);
});

test('GET /user/addresses returns addresses with expected structure', function () {
    [$user, $token] = akubicaCustomerToken();

    Address::factory()->for($user->customer)->create([
        'street' => 'Calle Reforma',
        'number' => '123',
        'neighborhood' => 'Centro',
        'city' => 'Monterrey',
        'state' => 'Nuevo León',
        'zipcode' => '64000',
    ]);

    $this->getJson('/api/v1/user/addresses', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.addresses')
        ->assertJsonPath('data.addresses.0.street', 'Calle Reforma')
        ->assertJsonPath('data.addresses.0.external_number', '123')
        ->assertJsonPath('data.addresses.0.neighborhood', 'Centro')
        ->assertJsonPath('data.addresses.0.city', 'Monterrey')
        ->assertJsonPath('data.addresses.0.state', 'Nuevo León')
        ->assertJsonPath('data.addresses.0.postal_code', '64000')
        ->assertJsonPath('data.addresses.0.country', 'MX');
});

test('GET /user/addresses only returns addresses of authenticated customer', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    Address::factory()->for($owner->customer)->create(['street' => 'Calle Propia']);
    Address::factory()->for($other->customer)->create(['street' => 'Calle Ajena']);

    $this->getJson('/api/v1/user/addresses', authHeaders($ownerToken))
        ->assertOk()
        ->assertJsonCount(1, 'data.addresses')
        ->assertJsonPath('data.addresses.0.street', 'Calle Propia');
});

// ── Payment methods ───────────────────────────────────────────────────

test('GET /user/payment-methods with no methods returns empty array', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/user/payment-methods', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => ['payment_methods' => []],
        ]);
});

test('GET /user/payment-methods returns safe payment method structure', function () {
    [$user, $token] = akubicaCustomerToken();

    EfevooToken::query()->create([
        'customer_id' => $user->customer->id,
        'card_last_four' => '4242',
        'card_brand' => 'Visa',
        'card_expiration' => '1228',
        'card_holder' => 'Nombre Usuario',
        'client_token' => 'secret-client-token-value',
        'card_token' => 'secret-card-token-value',
        'environment' => 'test',
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/user/payment-methods', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.payment_methods')
        ->assertJsonPath('data.payment_methods.0.brand', 'visa')
        ->assertJsonPath('data.payment_methods.0.last4', '4242')
        ->assertJsonPath('data.payment_methods.0.expiration_month', '12')
        ->assertJsonPath('data.payment_methods.0.expiration_year', '2028')
        ->assertJsonPath('data.payment_methods.0.holder_name', 'Nombre Usuario')
        ->assertJsonPath('data.payment_methods.0.type', 'card');
});

test('GET /user/payment-methods does not expose sensitive fields', function () {
    [$user, $token] = akubicaCustomerToken();

    EfevooToken::query()->create([
        'customer_id' => $user->customer->id,
        'card_last_four' => '9999',
        'card_brand' => 'MasterCard',
        'card_expiration' => '0130',
        'card_holder' => 'Titular Tarjeta',
        'client_token' => 'super-secret-client',
        'card_token' => 'super-secret-card',
        'environment' => 'test',
        'is_active' => true,
    ]);

    $json = json_encode($this->getJson('/api/v1/user/payment-methods', authHeaders($token))->json());

    expect($json)->not->toContain('super-secret-client')
        ->and($json)->not->toContain('super-secret-card')
        ->and($json)->not->toContain('cvv')
        ->and($json)->not->toContain('pan')
        ->and($json)->not->toContain('card_number')
        ->and($json)->not->toContain('sensitive_token');
});

test('GET /user/payment-methods only returns methods of authenticated customer', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    EfevooToken::query()->create([
        'customer_id' => $owner->customer->id,
        'card_last_four' => '1111',
        'card_brand' => 'Visa',
        'card_expiration' => '1228',
        'environment' => 'test',
        'is_active' => true,
    ]);

    EfevooToken::query()->create([
        'customer_id' => $other->customer->id,
        'card_last_four' => '2222',
        'card_brand' => 'Visa',
        'card_expiration' => '1228',
        'environment' => 'test',
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/user/payment-methods', authHeaders($ownerToken))
        ->assertOk()
        ->assertJsonCount(1, 'data.payment_methods')
        ->assertJsonPath('data.payment_methods.0.last4', '1111');
});

// ── Regresión 501 ─────────────────────────────────────────────────────

test('GET /catalog/medications/{id} returns 503 CATALOG_UNAVAILABLE', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/medications/1', authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'CATALOG_UNAVAILABLE');
});
