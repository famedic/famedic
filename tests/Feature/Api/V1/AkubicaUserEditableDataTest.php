<?php

use App\Enums\Gender;
use App\Models\Address;
use App\Models\Contact;
use App\Models\User;

function validContactPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Juan',
        'paternal_lastname' => 'Pérez',
        'maternal_lastname' => 'López',
        'phone' => '8112345678',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE->value,
    ], $overrides);
}

function validAddressPayload(array $overrides = []): array
{
    return array_merge([
        'street' => 'Av. Siempre Viva',
        'number' => '123',
        'neighborhood' => 'Centro',
        'city' => 'Monterrey',
        'state' => 'Nuevo León',
        'zipcode' => '64000',
        'additional_references' => 'Casa azul',
    ], $overrides);
}

// ── Auth ──────────────────────────────────────────────────────────────

test('GET /user/contacts without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/user/contacts')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('POST /user/addresses without token returns 401 UNAUTHENTICATED', function () {
    $this->postJson('/api/v1/user/addresses', validAddressPayload())
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('POST /user/contacts with user without customer returns 403 FORBIDDEN', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->postJson('/api/v1/user/contacts', validContactPayload(), authHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

// ── Contacts ────────────────────────────────────────────────────────

test('GET /user/contacts with no contacts returns empty array', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/user/contacts', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => ['contacts' => []],
        ]);
});

test('GET /user/contacts returns contacts with expected structure', function () {
    [$user, $token] = akubicaCustomerToken();

    Contact::factory()->for($user->customer)->create([
        'name' => 'María',
        'paternal_lastname' => 'García',
        'maternal_lastname' => 'Ruiz',
        'birth_date' => '1985-06-15',
        'gender' => Gender::FEMALE,
        'phone' => '8187654321',
        'phone_country' => 'MX',
    ]);

    $this->getJson('/api/v1/user/contacts', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.contacts')
        ->assertJsonPath('data.contacts.0.full_name', 'María García Ruiz')
        ->assertJsonPath('data.contacts.0.name', 'María')
        ->assertJsonPath('data.contacts.0.paternal_lastname', 'García')
        ->assertJsonPath('data.contacts.0.maternal_lastname', 'Ruiz')
        ->assertJsonPath('data.contacts.0.birth_date', '1985-06-15')
        ->assertJsonPath('data.contacts.0.gender', 'female')
        ->assertJsonPath('data.contacts.0.phone_country', 'MX');
});

test('GET /user/contacts only returns contacts of authenticated customer', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    Contact::factory()->for($owner->customer)->create(['name' => 'Propio']);
    Contact::factory()->for($other->customer)->create(['name' => 'Ajeno']);

    $this->getJson('/api/v1/user/contacts', authHeaders($ownerToken))
        ->assertOk()
        ->assertJsonCount(1, 'data.contacts')
        ->assertJsonPath('data.contacts.0.name', 'Propio');
});

test('POST /user/contacts with valid payload returns 201', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/user/contacts', validContactPayload(), authHeaders($token))
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.contact.name', 'Juan')
        ->assertJsonPath('data.contact.paternal_lastname', 'Pérez')
        ->assertJsonPath('data.contact.gender', 'male')
        ->assertJsonPath('data.contact.birth_date', '1990-01-01');
});

test('POST /user/contacts with invalid payload returns 422 VALIDATION_ERROR', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/user/contacts', ['name' => ''], authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('PUT /user/contacts/{id} updates own contact', function () {
    [$user, $token] = akubicaCustomerToken();

    $contact = Contact::factory()->for($user->customer)->create([
        'name' => 'Original',
        'paternal_lastname' => 'Apellido',
        'maternal_lastname' => 'Materno',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
        'phone' => '8111111111',
        'phone_country' => 'MX',
    ]);

    $this->putJson(
        "/api/v1/user/contacts/{$contact->id}",
        validContactPayload(['name' => 'Actualizado']),
        authHeaders($token),
    )
        ->assertOk()
        ->assertJsonPath('data.contact.name', 'Actualizado');
});

test('PUT /user/contacts/{id} for another customer returns 404 CONTACT_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $otherContact = Contact::factory()->for($other->customer)->create();

    $this->putJson(
        "/api/v1/user/contacts/{$otherContact->id}",
        validContactPayload(),
        authHeaders($ownerToken),
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');
});

test('DELETE /user/contacts/{id} deletes own contact', function () {
    [$user, $token] = akubicaCustomerToken();

    $contact = Contact::factory()->for($user->customer)->create();

    $this->deleteJson("/api/v1/user/contacts/{$contact->id}", [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(Contact::query()->find($contact->id))->toBeNull();
});

test('DELETE /user/contacts/{id} for another customer returns 404 CONTACT_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $otherContact = Contact::factory()->for($other->customer)->create();

    $this->deleteJson("/api/v1/user/contacts/{$otherContact->id}", [], authHeaders($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');
});

test('DELETE /user/contacts/{id} when contact does not exist returns 404 CONTACT_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();

    $this->deleteJson('/api/v1/user/contacts/999999', [], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');
});

// ── Addresses ───────────────────────────────────────────────────────

test('POST /user/addresses with valid payload returns 201', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/user/addresses', validAddressPayload(), authHeaders($token))
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.address.street', 'Av. Siempre Viva')
        ->assertJsonPath('data.address.external_number', '123')
        ->assertJsonPath('data.address.city', 'Monterrey')
        ->assertJsonPath('data.address.state', 'Nuevo León')
        ->assertJsonPath('data.address.postal_code', '64000')
        ->assertJsonPath('data.address.country', 'MX');
});

test('POST /user/addresses with invalid payload returns 422 VALIDATION_ERROR', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/user/addresses', ['street' => ''], authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('PUT /user/addresses/{id} updates own address', function () {
    [$user, $token] = akubicaCustomerToken();

    $address = Address::factory()->for($user->customer)->create([
        'street' => 'Calle Original',
        'number' => '1',
        'neighborhood' => 'Centro',
        'city' => 'Monterrey',
        'state' => 'Nuevo León',
        'zipcode' => '64000',
    ]);

    $this->putJson(
        "/api/v1/user/addresses/{$address->id}",
        validAddressPayload(['street' => 'Calle Actualizada']),
        authHeaders($token),
    )
        ->assertOk()
        ->assertJsonPath('data.address.street', 'Calle Actualizada');
});

test('PUT /user/addresses/{id} for another customer returns 404 ADDRESS_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $otherAddress = Address::factory()->for($other->customer)->create();

    $this->putJson(
        "/api/v1/user/addresses/{$otherAddress->id}",
        validAddressPayload(),
        authHeaders($ownerToken),
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ADDRESS_NOT_FOUND');
});

test('DELETE /user/addresses/{id} deletes own address', function () {
    [$user, $token] = akubicaCustomerToken();

    $address = Address::factory()->for($user->customer)->create();

    $this->deleteJson("/api/v1/user/addresses/{$address->id}", [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(Address::query()->find($address->id))->toBeNull();
});

test('DELETE /user/addresses/{id} for another customer returns 404 ADDRESS_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $otherAddress = Address::factory()->for($other->customer)->create();

    $this->deleteJson("/api/v1/user/addresses/{$otherAddress->id}", [], authHeaders($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ADDRESS_NOT_FOUND');
});

test('DELETE /user/addresses/{id} when address does not exist returns 404 ADDRESS_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();

    $this->deleteJson('/api/v1/user/addresses/999999', [], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ADDRESS_NOT_FOUND');
});

test('GET /user/addresses still works and shows created address', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/user/addresses', validAddressPayload(), authHeaders($token))
        ->assertCreated();

    $this->getJson('/api/v1/user/addresses', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.addresses')
        ->assertJsonPath('data.addresses.0.street', 'Av. Siempre Viva')
        ->assertJsonPath('data.addresses.0.external_number', '123')
        ->assertJsonPath('data.addresses.0.postal_code', '64000');
});

// ── Regresión ───────────────────────────────────────────────────────

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
