<?php

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Contact;
use App\Models\EfevooToken;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\TaxProfile;
use App\Models\User;

// ── Auth ──────────────────────────────────────────────────────────────

test('GET /cart/totals without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/cart/totals?brand=olab')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('DELETE /cart without token returns 401 UNAUTHENTICATED', function () {
    $this->deleteJson('/api/v1/cart?brand=olab')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /checkout/prepare without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/checkout/prepare?brand=olab')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('POST /checkout/draft without token returns 401 UNAUTHENTICATED', function () {
    $this->postJson('/api/v1/checkout/draft', ['brand' => 'olab'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /checkout/prepare with user without customer returns 403 FORBIDDEN', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->getJson('/api/v1/checkout/prepare?brand=olab', authHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

// ── Cart totals ───────────────────────────────────────────────────────

test('GET /cart/totals with empty cart returns 200 with zero totals', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/cart/totals?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'brand' => 'olab',
                'currency' => 'MXN',
                'items_count' => 0,
                'subtotal_cents' => 0,
                'discount_cents' => 0,
                'total_cents' => 0,
                'coupon' => null,
            ],
        ]);
});

test('GET /cart/totals with items returns correct totals', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    addOlabCartItem($user, createOlabTest([
        'famedic_price_cents' => 35000,
        'public_price_cents' => 40000,
    ]));

    $this->getJson('/api/v1/cart/totals?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.items_count', 2)
        ->assertJsonPath('data.subtotal_cents', 85000)
        ->assertJsonPath('data.total_cents', 70000)
        ->assertJsonPath('data.discount_cents', 15000);
});

test('GET /cart/totals without brand returns 422 VALIDATION_ERROR', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/cart/totals', authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('GET /cart/totals with invalid brand returns 422 VALIDATION_ERROR', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/cart/totals?brand=invalid', authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

// ── Clear cart ────────────────────────────────────────────────────────

test('DELETE /cart with empty cart returns 200 deleted_count 0', function () {
    [, $token] = akubicaCustomerToken();

    $this->deleteJson('/api/v1/cart?brand=olab', [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('data.deleted_count', 0);
});

test('DELETE /cart with items returns correct deleted_count', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    addOlabCartItem($user);

    $this->deleteJson('/api/v1/cart?brand=olab', [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.deleted', true)
        ->assertJsonPath('data.deleted_count', 2);

    $this->getJson('/api/v1/cart?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(0, 'data.items');
});

test('DELETE /cart does not remove items from another brand', function () {
    [$user, $token] = akubicaCustomerToken();

    addOlabCartItem($user);

    $swissTest = LaboratoryTest::factory()->create(['brand' => LaboratoryBrand::SWISSLAB]);
    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $swissTest->id,
    ]);

    $this->deleteJson('/api/v1/cart?brand=olab', [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.deleted_count', 1);

    $this->getJson('/api/v1/cart?brand=swisslab', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.items');
});

test('DELETE /cart does not remove items from another customer', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    addOlabCartItem($owner);
    addOlabCartItem($other);

    $this->deleteJson('/api/v1/cart?brand=olab', [], authHeaders($ownerToken))
        ->assertOk()
        ->assertJsonPath('data.deleted_count', 1);

    expect(
        LaboratoryCartItem::query()
            ->where('customer_id', $other->customer->id)
            ->count(),
    )->toBe(1);
});

// ── Checkout prepare ──────────────────────────────────────────────────

test('GET /checkout/prepare with empty cart returns EMPTY_CART warning', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/checkout/prepare?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.requirements.has_items', false)
        ->assertJsonPath('data.warnings.0.code', 'EMPTY_CART')
        ->assertJsonPath('data.can_continue_to_payment_platform', false);
});

test('GET /checkout/prepare with items returns cart totals contacts and addresses', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    Contact::factory()->for($user->customer)->create(['name' => 'Paciente']);
    Address::factory()->for($user->customer)->create(['street' => 'Calle Test']);

    $this->getJson('/api/v1/checkout/prepare?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.brand', 'olab')
        ->assertJsonCount(1, 'data.cart.items')
        ->assertJsonPath('data.cart.totals.total_cents', 35000)
        ->assertJsonCount(1, 'data.contacts')
        ->assertJsonCount(1, 'data.addresses')
        ->assertJsonPath('data.requirements.has_items', true)
        ->assertJsonPath('data.requirements.requires_contact', true)
        ->assertJsonPath('data.requirements.requires_address', true);
});

test('GET /checkout/prepare sets can_continue_to_payment_platform true when cart has items', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    $this->getJson('/api/v1/checkout/prepare?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.can_continue_to_payment_platform', true);
});

test('GET /checkout/prepare sets requires_appointment true when cart item requires appointment', function () {
    [$user, $token] = akubicaCustomerToken();

    addOlabCartItem($user, createOlabTest(['requires_appointment' => true]));

    $this->getJson('/api/v1/checkout/prepare?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.requirements.requires_appointment', true)
        ->assertJsonPath('data.warnings.0.code', 'REQUIRES_APPOINTMENT');
});

test('GET /checkout/prepare payment_methods do not expose sensitive tokens', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    EfevooToken::query()->create([
        'customer_id' => $user->customer->id,
        'card_last_four' => '4242',
        'card_brand' => 'Visa',
        'card_expiration' => '1228',
        'card_holder' => 'Titular',
        'client_token' => 'secret-client-token',
        'card_token' => 'secret-card-token',
        'environment' => 'test',
        'is_active' => true,
    ]);

    $json = json_encode($this->getJson('/api/v1/checkout/prepare?brand=olab', authHeaders($token))->json());

    expect($json)->not->toContain('secret-client-token')
        ->and($json)->not->toContain('secret-card-token');
});

// ── Checkout draft ────────────────────────────────────────────────────

test('POST /checkout/draft with empty cart returns 409 EMPTY_CART', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/checkout/draft', ['brand' => 'olab'], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EMPTY_CART');
});

test('POST /checkout/draft with foreign contact_id returns 404 CONTACT_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    addOlabCartItem($owner);
    $otherContact = Contact::factory()->for($other->customer)->create();

    $this->postJson('/api/v1/checkout/draft', [
        'brand' => 'olab',
        'contact_id' => $otherContact->id,
    ], authHeaders($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');
});

test('POST /checkout/draft with foreign address_id returns 404 ADDRESS_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    addOlabCartItem($owner);
    $otherAddress = Address::factory()->for($other->customer)->create();

    $this->postJson('/api/v1/checkout/draft', [
        'brand' => 'olab',
        'address_id' => $otherAddress->id,
    ], authHeaders($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ADDRESS_NOT_FOUND');
});

test('POST /checkout/draft with own contact and address returns 200 draft', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user, createOlabTest(['requires_appointment' => false]));

    $contact = Contact::factory()->for($user->customer)->create([
        'name' => 'Juan',
        'paternal_lastname' => 'Pérez',
        'maternal_lastname' => 'López',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
    ]);

    $address = Address::factory()->for($user->customer)->create([
        'street' => 'Av. Test',
        'city' => 'Monterrey',
        'state' => 'Nuevo León',
    ]);

    $this->postJson('/api/v1/checkout/draft', [
        'brand' => 'olab',
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'notes' => 'Horario matutino',
    ], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.draft.brand', 'olab')
        ->assertJsonPath('data.draft.contact_id', $contact->id)
        ->assertJsonPath('data.draft.address_id', $address->id)
        ->assertJsonPath('data.draft.notes', 'Horario matutino')
        ->assertJsonPath('data.draft.is_ready_for_payment_link', true)
        ->assertJsonPath('data.requirements.has_contact', true)
        ->assertJsonPath('data.requirements.has_required_address', true);

    expect(LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::OLAB)
        ->value('contact_id')
    )->toBe($contact->id);
});

test('POST /checkout/draft with foreign tax_profile_id returns 404 TAX_PROFILE_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    addOlabCartItem($owner);
    $otherProfile = TaxProfile::factory()->for($other->customer)->create();

    $this->postJson('/api/v1/checkout/draft', [
        'brand' => 'olab',
        'tax_profile_id' => $otherProfile->id,
    ], authHeaders($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'TAX_PROFILE_NOT_FOUND');
});

test('POST /checkout/draft with notes too long returns 422 VALIDATION_ERROR', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    $this->postJson('/api/v1/checkout/draft', [
        'brand' => 'olab',
        'notes' => str_repeat('a', 1001),
    ], authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('POST /checkout/draft does not create a laboratory purchase', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    $contact = Contact::factory()->for($user->customer)->create();
    $address = Address::factory()->for($user->customer)->create();

    $purchasesBefore = LaboratoryPurchase::query()->count();

    $this->postJson('/api/v1/checkout/draft', [
        'brand' => 'olab',
        'contact_id' => $contact->id,
        'address_id' => $address->id,
    ], authHeaders($token))->assertOk();

    expect(LaboratoryPurchase::query()->count())->toBe($purchasesBefore);
});

test('POST /checkout/draft does not store payment_method on draft', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    $contact = Contact::factory()->for($user->customer)->create();
    $address = Address::factory()->for($user->customer)->create();

    $this->postJson('/api/v1/checkout/draft', [
        'brand' => 'olab',
        'contact_id' => $contact->id,
        'address_id' => $address->id,
    ], authHeaders($token))->assertOk();

    $draft = LaboratoryCheckoutDraft::query()
        ->where('customer_id', $user->customer->id)
        ->where('laboratory_brand', LaboratoryBrand::OLAB)
        ->first();

    expect($draft->payment_method)->toBeNull();
});

// ── Regresión ─────────────────────────────────────────────────────────

test('GET /catalog/medications/{id} still returns 503 CATALOG_UNAVAILABLE', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/medications/1', authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'CATALOG_UNAVAILABLE');
});

test('PUT /orders/{id}/cancel still returns 503 FEATURE_DISABLED', function () {
    [, $token] = akubicaCustomerToken();

    $this->putJson('/api/v1/orders/1/cancel', [], authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');
});
