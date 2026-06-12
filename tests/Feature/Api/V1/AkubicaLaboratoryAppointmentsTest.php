<?php

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Contact;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\User;

function setupAppointmentRequiredCart(User $user): array
{
    $test = createOlabTest(['requires_appointment' => true]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    $contact = Contact::factory()->for($user->customer)->create([
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
    ]);

    $address = Address::factory()->for($user->customer)->create([
        'city' => 'Monterrey',
        'state' => 'Nuevo León',
    ]);

    return [$contact, $address, $test];
}

function validAppointmentPayload(int $contactId, int $addressId, array $overrides = []): array
{
    return array_merge([
        'brand' => 'olab',
        'contact_id' => $contactId,
        'address_id' => $addressId,
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
        'notes' => 'Prefiere horario matutino',
    ], $overrides);
}

// ── Auth ──────────────────────────────────────────────────────────────

test('GET /laboratory-appointments/requirements without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/laboratory-appointments/requirements?brand=olab')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /laboratory-appointments without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/laboratory-appointments')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('POST /laboratory-appointments without token returns 401 UNAUTHENTICATED', function () {
    $this->postJson('/api/v1/laboratory-appointments', ['brand' => 'olab'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('DELETE /laboratory-appointments/{id} without token returns 401 UNAUTHENTICATED', function () {
    $this->deleteJson('/api/v1/laboratory-appointments/1')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /laboratory-appointments with user without customer returns 403 FORBIDDEN', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->getJson('/api/v1/laboratory-appointments', authHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

// ── Requirements ──────────────────────────────────────────────────────

test('GET /laboratory-appointments/requirements with empty cart returns warning EMPTY_CART', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/laboratory-appointments/requirements?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.requires_appointment', false)
        ->assertJsonPath('data.warnings.0.code', 'EMPTY_CART')
        ->assertJsonPath('data.can_continue_to_payment_link', false);
});

test('GET /laboratory-appointments/requirements with cart without appointment studies returns requires_appointment false', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user, createOlabTest(['requires_appointment' => false]));

    $this->getJson('/api/v1/laboratory-appointments/requirements?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.requires_appointment', false)
        ->assertJsonPath('data.has_appointment', false)
        ->assertJsonPath('data.can_continue_to_payment_link', true);
});

test('GET /laboratory-appointments/requirements with appointment study returns requires_appointment true', function () {
    [$user, $token] = akubicaCustomerToken();
    setupAppointmentRequiredCart($user);

    $this->getJson('/api/v1/laboratory-appointments/requirements?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.requires_appointment', true)
        ->assertJsonPath('data.has_appointment', false)
        ->assertJsonPath('data.warnings.0.code', 'APPOINTMENT_REQUIRED')
        ->assertJsonPath('data.can_continue_to_payment_link', false)
        ->assertJsonCount(1, 'data.required_items');
});

test('GET /laboratory-appointments/requirements with existing appointment returns has_appointment true', function () {
    [$user, $token] = akubicaCustomerToken();
    setupAppointmentRequiredCart($user);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'confirmed_at' => null,
    ]);

    $this->getJson('/api/v1/laboratory-appointments/requirements?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.has_appointment', true)
        ->assertJsonPath('data.can_continue_to_payment_link', true);
});

// ── List ──────────────────────────────────────────────────────────────

test('GET /laboratory-appointments with no appointments returns empty array', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/laboratory-appointments', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.appointments', [])
        ->assertJsonPath('data.pagination.total', 0);
});

test('GET /laboratory-appointments returns appointments with expected structure', function () {
    [$user, $token] = akubicaCustomerToken();
    [$contact, $address] = setupAppointmentRequiredCart($user);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
    ]);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'patient_name' => $contact->name,
        'patient_paternal_lastname' => $contact->paternal_lastname,
        'patient_maternal_lastname' => $contact->maternal_lastname,
        'callback_availability_starts_at' => now()->addDays(2),
        'confirmed_at' => null,
    ]);

    $this->getJson('/api/v1/laboratory-appointments', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.appointments')
        ->assertJsonPath('data.appointments.0.brand', 'olab')
        ->assertJsonPath('data.appointments.0.status', 'pending')
        ->assertJsonPath('data.appointments.0.contact.id', $contact->id);
});

test('GET /laboratory-appointments only returns appointments of authenticated customer', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    LaboratoryAppointment::factory()->create([
        'customer_id' => $owner->customer->id,
        'brand' => LaboratoryBrand::OLAB,
    ]);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $other->customer->id,
        'brand' => LaboratoryBrand::OLAB,
    ]);

    $this->getJson('/api/v1/laboratory-appointments', authHeaders($ownerToken))
        ->assertOk()
        ->assertJsonCount(1, 'data.appointments');
});

test('GET /laboratory-appointments filters by brand', function () {
    [$user, $token] = akubicaCustomerToken();

    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
    ]);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::SWISSLAB,
    ]);

    $this->getJson('/api/v1/laboratory-appointments?brand=olab', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.appointments')
        ->assertJsonPath('data.appointments.0.brand', 'olab');
});

test('GET /laboratory-appointments filters by status pending', function () {
    [$user, $token] = akubicaCustomerToken();

    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'confirmed_at' => null,
    ]);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'confirmed_at' => now(),
    ]);

    $this->getJson('/api/v1/laboratory-appointments?status=pending', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.appointments')
        ->assertJsonPath('data.appointments.0.status', 'pending');
});

// ── Store ─────────────────────────────────────────────────────────────

test('POST /laboratory-appointments with empty cart returns 409 EMPTY_CART', function () {
    [$user, $token] = akubicaCustomerToken();
    [$contact, $address] = setupAppointmentRequiredCart($user);

    LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->delete();

    $this->postJson(
        '/api/v1/laboratory-appointments',
        validAppointmentPayload($contact->id, $address->id),
        authHeaders($token),
    )
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EMPTY_CART');
});

test('POST /laboratory-appointments when appointment not required returns 409 APPOINTMENT_NOT_REQUIRED', function () {
    [$user, $token] = akubicaCustomerToken();
    [$contact, $address] = setupAppointmentRequiredCart($user);

    LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->delete();
    addOlabCartItem($user, createOlabTest(['requires_appointment' => false]));

    $this->postJson(
        '/api/v1/laboratory-appointments',
        validAppointmentPayload($contact->id, $address->id),
        authHeaders($token),
    )
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'APPOINTMENT_NOT_REQUIRED');
});

test('POST /laboratory-appointments with foreign contact returns 404 CONTACT_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    setupAppointmentRequiredCart($owner);
    [$otherContact, $otherAddress] = setupAppointmentRequiredCart($other);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        validAppointmentPayload($otherContact->id, $otherAddress->id),
        authHeaders($ownerToken),
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');
});

test('POST /laboratory-appointments with foreign address returns 404 ADDRESS_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    [$contact] = setupAppointmentRequiredCart($owner);
    [, $otherAddress] = setupAppointmentRequiredCart($other);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        validAppointmentPayload($contact->id, $otherAddress->id),
        authHeaders($ownerToken),
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ADDRESS_NOT_FOUND');
});

test('POST /laboratory-appointments with past scheduled_at returns 422 VALIDATION_ERROR', function () {
    [$user, $token] = akubicaCustomerToken();
    [$contact, $address] = setupAppointmentRequiredCart($user);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        validAppointmentPayload($contact->id, $address->id, [
            'scheduled_at' => now()->subDay()->toIso8601String(),
        ]),
        authHeaders($token),
    )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('POST /laboratory-appointments with valid payload returns 201', function () {
    [$user, $token] = akubicaCustomerToken();
    [$contact, $address] = setupAppointmentRequiredCart($user);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        validAppointmentPayload($contact->id, $address->id),
        authHeaders($token),
    )
        ->assertCreated()
        ->assertJsonPath('data.appointment.brand', 'olab')
        ->assertJsonPath('data.appointment.status', 'pending')
        ->assertJsonPath('data.appointment.contact_id', $contact->id)
        ->assertJsonPath('data.appointment.address_id', $address->id)
        ->assertJsonPath('data.can_continue_to_payment_link', true);
});

test('POST /laboratory-appointments duplicate pending returns 409 APPOINTMENT_ALREADY_EXISTS', function () {
    [$user, $token] = akubicaCustomerToken();
    [$contact, $address] = setupAppointmentRequiredCart($user);

    $payload = validAppointmentPayload($contact->id, $address->id);

    $this->postJson('/api/v1/laboratory-appointments', $payload, authHeaders($token))->assertCreated();

    $this->postJson('/api/v1/laboratory-appointments', $payload, authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'APPOINTMENT_ALREADY_EXISTS');
});

test('POST /laboratory-appointments valid allows payment link afterwards', function () {
    [$user, $token] = akubicaCustomerToken();
    [$contact, $address] = setupAppointmentRequiredCart($user);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        validAppointmentPayload($contact->id, $address->id),
        authHeaders($token),
    )->assertCreated();

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.payment_link.is_ready', true);
});

// ── Destroy ───────────────────────────────────────────────────────────

test('DELETE /laboratory-appointments/{id} deletes own appointment', function () {
    [$user, $token] = akubicaCustomerToken();

    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
    ]);

    $this->deleteJson("/api/v1/laboratory-appointments/{$appointment->id}", [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(LaboratoryAppointment::query()->find($appointment->id))->toBeNull();
});

test('DELETE /laboratory-appointments/{id} for another customer returns 404 APPOINTMENT_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $other->customer->id,
        'brand' => LaboratoryBrand::OLAB,
    ]);

    $this->deleteJson("/api/v1/laboratory-appointments/{$appointment->id}", [], authHeaders($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'APPOINTMENT_NOT_FOUND');
});

test('DELETE /laboratory-appointments/{id} when not exists returns 404 APPOINTMENT_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();

    $this->deleteJson('/api/v1/laboratory-appointments/999999', [], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'APPOINTMENT_NOT_FOUND');
});

test('DELETE required appointment blocks payment link again with APPOINTMENT_REQUIRED', function () {
    [$user, $token] = akubicaCustomerToken();
    [$contact, $address] = setupAppointmentRequiredCart($user);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        validAppointmentPayload($contact->id, $address->id),
        authHeaders($token),
    )->assertCreated();

    $appointmentId = LaboratoryAppointment::query()
        ->where('customer_id', $user->customer->id)
        ->value('id');

    $this->deleteJson("/api/v1/laboratory-appointments/{$appointmentId}", [], authHeaders($token))
        ->assertOk();

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'APPOINTMENT_REQUIRED');
});

// ── Regresión ─────────────────────────────────────────────────────────

test('POST /checkout/payment-link blocks when appointment required and missing', function () {
    [$user, $token] = akubicaCustomerToken();
    [$contact, $address] = setupAppointmentRequiredCart($user);

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
    ]);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'APPOINTMENT_REQUIRED');
});

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
