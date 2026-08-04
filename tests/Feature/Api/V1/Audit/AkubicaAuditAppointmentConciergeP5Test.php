<?php

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\Api\V1\ApiV1AuditEvent;
use App\Models\Contact;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\User;
use App\Services\Api\V1\Audit\AppointmentConciergeAuditRecorder;
use App\Services\Api\V1\Audit\AuditActorResolver;
use App\Services\Api\V1\Audit\AuditEventDefinitions;
use App\Services\Api\V1\Audit\AuditEventWriter;
use App\Services\Api\V1\Audit\AuditMetadataNormalizer;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Idempotency\IdempotencyKey;
use App\Support\Api\V1\AkubicaCorrelationId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('api_v1.audit.enabled', false);
    config()->set('api_v1.idempotency.enabled', false);
});

afterEach(function () {
    config()->set('api_v1.audit.enabled', false);
    config()->set('api_v1.idempotency.enabled', false);
});

function enableAppointmentConciergeAudit(): void
{
    config()->set('api_v1.audit.enabled', true);
    app()->forgetInstance(AuditMetadataNormalizer::class);
    app()->forgetInstance(AuditEventWriter::class);
    app()->forgetInstance(AppointmentConciergeAuditRecorder::class);
    app()->forgetInstance(AuditActorResolver::class);
}

/**
 * @return array{0: User, 1: string, 2: int}
 */
function apptAuditCustomerToken(array $userAttrs = []): array
{
    static $phoneSeq = 8100000000;

    $phoneSeq++;

    $user = User::factory()->withRegularCustomer()->create(array_merge([
        'phone' => (string) $phoneSeq,
        'phone_country' => 'MX',
        'phone_verified_at' => now(),
        'email' => 'appt.audit.'.$phoneSeq.'@ejemplo.com',
    ], $userAttrs));

    $newToken = $user->createToken('akubica-test');

    return [$user, $newToken->plainTextToken, (int) $newToken->accessToken->id];
}

/**
 * @return array{0: Contact, 1: Address}
 */
function apptAuditSetupRequiredCart(User $user): array
{
    $test = createOlabTest(['requires_appointment' => true]);

    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    $contact = Contact::factory()->for($user->customer)->create([
        'name' => 'PacienteAudit',
        'paternal_lastname' => 'Lopez',
        'maternal_lastname' => 'Suarez',
        'birth_date' => '1990-01-01',
        'gender' => Gender::MALE,
        'phone' => '8181112233',
        'phone_country' => 'MX',
    ]);

    $address = Address::factory()->for($user->customer)->create([
        'street' => 'Calle Sensible 123',
        'city' => 'Monterrey',
        'state' => 'Nuevo León',
        'neighborhood' => 'Centro',
        'zipcode' => '64000',
    ]);

    return [$contact, $address];
}

function apptAuditPayload(int $contactId, int $addressId, array $overrides = []): array
{
    return array_merge([
        'brand' => 'olab',
        'contact_id' => $contactId,
        'address_id' => $addressId,
        'scheduled_at' => now()->addDays(3)->startOfDay()->toIso8601String(),
        'notes' => 'Prefiere horario matutino con sintomas de tos',
    ], $overrides);
}

function apptAuditAssertNoSecrets(?ApiV1AuditEvent $event = null, array $extraForbidden = []): void
{
    $rows = $event !== null
        ? [DB::table('api_v1_audit_events')->where('id', $event->id)->first()]
        : DB::table('api_v1_audit_events')->get()->all();

    foreach ($rows as $row) {
        $blob = json_encode($row);
        expect($blob)->not->toContain('Bearer')
            ->and($blob)->not->toContain('+5281')
            ->and($blob)->not->toContain('8187654321')
            ->and($blob)->not->toContain('8181112233')
            ->and($blob)->not->toContain('@ejemplo.com')
            ->and($blob)->not->toContain('PacienteAudit')
            ->and($blob)->not->toContain('Calle Sensible')
            ->and($blob)->not->toContain('Prefiere horario')
            ->and($blob)->not->toContain('sintomas')
            ->and($blob)->not->toContain('Idempotency-Key')
            ->and($blob)->not->toContain('idem-key-')
            ->and($blob)->not->toContain('api_v1.payment.')
            ->and($blob)->not->toContain('api_v1.orders.created')
            ->and($blob)->not->toContain('api_v1.cart.')
            ->and($blob)->not->toContain('callback_completed')
            ->and($blob)->not->toContain('appointments.confirmed');

        foreach ($extraForbidden as $needle) {
            expect($blob)->not->toContain($needle);
        }
    }
}

function apptAuditLatest(string $eventName): ?ApiV1AuditEvent
{
    return ApiV1AuditEvent::query()
        ->where('event_name', $eventName)
        ->orderByDesc('id')
        ->first();
}

// ── Flag OFF / alcance ───────────────────────────────────────────────────

test('flag OFF appointment mutations work without audit inserts', function () {
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($contact->id, $address->id),
        authHeaders($token),
    )->assertCreated();

    $appointmentId = LaboratoryAppointment::query()
        ->where('customer_id', $user->customer->id)
        ->value('id');

    $this->deleteJson("/api/v1/laboratory-appointments/{$appointmentId}", [], authHeaders($token))
        ->assertOk();

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});

test('block 5 does not audit GET requirements index or payment-link', function () {
    enableAppointmentConciergeAudit();
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);

    $this->getJson('/api/v1/laboratory-appointments/requirements?brand=olab', authHeaders($token))
        ->assertOk();
    $this->getJson('/api/v1/laboratory-appointments', authHeaders($token))->assertOk();

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'confirmation',
    ]);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'confirmed_at' => null,
    ]);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertOk();

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});

test('block 5 does not emit payment cart document or callback_completed events', function () {
    enableAppointmentConciergeAudit();
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($contact->id, $address->id),
        authHeaders($token),
    )->assertCreated();

    $names = ApiV1AuditEvent::query()->pluck('event_name')->all();
    expect($names)->toHaveCount(1)
        ->and($names[0])->toBe(AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED);

    foreach ($names as $name) {
        expect($name)->not->toStartWith('api_v1.payment.')
            ->and($name)->not->toStartWith('api_v1.cart.')
            ->and($name)->not->toStartWith('api_v1.results.')
            ->and($name)->not->toStartWith('api_v1.invoices.')
            ->and($name)->not->toBe('api_v1.orders.created')
            ->and($name)->not->toBe('api_v1.concierge.callback_requested')
            ->and($name)->not->toBe('api_v1.appointments.confirmed')
            ->and($name)->not->toBe('api_v1.appointments.availability_checked');
    }
});

// ── Request success ──────────────────────────────────────────────────────

test('POST laboratory-appointments success audits requested with actor resource and safe metadata', function () {
    enableAppointmentConciergeAudit();
    [$user, $token, $tokenId] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);
    $scheduled = now()->addDays(3)->startOfDay();
    $corr = 'corr-appt-requested-01';

    $response = $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($contact->id, $address->id, [
            'scheduled_at' => $scheduled->toIso8601String(),
        ]),
        array_merge(authHeaders($token), [
            AkubicaCorrelationId::HEADER => $corr,
        ]),
    )->assertCreated()
        ->assertJsonPath('data.appointment.status', 'pending');

    $appointmentId = (int) $response->json('data.appointment.id');
    $event = apptAuditLatest(AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED);

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->http_status)->toBe(201)
        ->and($event->error_code)->toBeNull()
        ->and($event->retryable)->toBeFalse()
        ->and($event->correlation_id)->toBe($corr)
        ->and($event->actor_type)->toBe('customer')
        ->and($event->actor_key)->toBe('customer:'.$user->customer->id)
        ->and($event->customer_id)->toBe($user->customer->id)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->personal_access_token_id)->toBe($tokenId)
        ->and($event->resource_type)->toBe(AppointmentConciergeAuditRecorder::RESOURCE_LABORATORY_APPOINTMENT)
        ->and($event->resource_key)->toBe((string) $appointmentId)
        ->and($event->metadata['laboratory_brand'])->toBe('olab')
        ->and($event->metadata['appointment_row_id'])->toBe($appointmentId)
        ->and($event->metadata['appointment_state'])->toBe('pending')
        ->and($event->metadata['scheduling_mode'])->toBe('callback_window')
        ->and($event->metadata['request_channel'])->toBe('akubica_api')
        ->and($event->metadata['requested_date'])->toBe($scheduled->toDateString())
        ->and($event->metadata['requested_window'])->toBe('one_hour')
        ->and($event->metadata['timezone'])->toBe((string) config('app.timezone', 'UTC'))
        ->and($event->metadata['checkout_draft_advanced'])->toBeTrue()
        ->and($event->metadata)->not->toHaveKey('notes')
        ->and($event->metadata)->not->toHaveKey('phone')
        ->and(ApiV1AuditEvent::query()->count())->toBe(1);

    expect(
        LaboratoryCheckoutDraft::query()
            ->where('customer_id', $user->customer->id)
            ->where('laboratory_brand', LaboratoryBrand::OLAB)
            ->where('checkout_step', 'confirmation')
            ->exists()
    )->toBeTrue();

    apptAuditAssertNoSecrets($event, [$scheduled->toIso8601String()]);
});

// ── Cancel success ───────────────────────────────────────────────────────

test('DELETE laboratory-appointments success audits cancelled', function () {
    enableAppointmentConciergeAudit();
    [$user, $token, $tokenId] = apptAuditCustomerToken();
    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'confirmed_at' => null,
        'notes' => 'Nota libre cancelacion',
    ]);
    $corr = 'corr-appt-cancelled-01';

    $this->deleteJson(
        "/api/v1/laboratory-appointments/{$appointment->id}",
        [],
        array_merge(authHeaders($token), [
            AkubicaCorrelationId::HEADER => $corr,
        ]),
    )->assertOk()
        ->assertJsonPath('data.deleted', true);

    $event = apptAuditLatest(AuditEventDefinitions::EVENT_APPOINTMENTS_CANCELLED);

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(AuditOutcome::SUCCEEDED)
        ->and($event->http_status)->toBe(200)
        ->and($event->correlation_id)->toBe($corr)
        ->and($event->actor_key)->toBe('customer:'.$user->customer->id)
        ->and($event->personal_access_token_id)->toBe($tokenId)
        ->and($event->resource_type)->toBe(AppointmentConciergeAuditRecorder::RESOURCE_LABORATORY_APPOINTMENT)
        ->and($event->resource_key)->toBe((string) $appointment->id)
        ->and($event->metadata['laboratory_brand'])->toBe('olab')
        ->and($event->metadata['appointment_row_id'])->toBe($appointment->id)
        ->and($event->metadata['previous_state'])->toBe('pending')
        ->and($event->metadata['resulting_state'])->toBe('cancelled')
        ->and($event->metadata)->not->toHaveKey('notes')
        ->and(ApiV1AuditEvent::query()->count())->toBe(1);

    apptAuditAssertNoSecrets($event, ['Nota libre cancelacion']);
});

// ── Rejections ───────────────────────────────────────────────────────────

test('POST EMPTY_CART audits rejected without appointment resource', function () {
    enableAppointmentConciergeAudit();
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);
    LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->delete();

    $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($contact->id, $address->id),
        authHeaders($token),
    )->assertStatus(409)->assertJsonPath('error.code', 'EMPTY_CART');

    $event = apptAuditLatest(AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED);
    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('EMPTY_CART')
        ->and($event->http_status)->toBe(409)
        ->and($event->retryable)->toBeFalse()
        ->and($event->resource_key)->toBeNull()
        ->and($event->metadata['laboratory_brand'])->toBe('olab')
        ->and($event->metadata)->not->toHaveKey('appointment_row_id');
});

test('POST APPOINTMENT_NOT_REQUIRED audits rejected', function () {
    enableAppointmentConciergeAudit();
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);
    LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->delete();
    addOlabCartItem($user, createOlabTest(['requires_appointment' => false]));

    $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($contact->id, $address->id),
        authHeaders($token),
    )->assertStatus(409)->assertJsonPath('error.code', 'APPOINTMENT_NOT_REQUIRED');

    $event = apptAuditLatest(AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED);
    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('APPOINTMENT_NOT_REQUIRED')
        ->and($event->resource_key)->toBeNull();
});

test('POST APPOINTMENT_ALREADY_EXISTS audits rejected', function () {
    enableAppointmentConciergeAudit();
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);
    $payload = apptAuditPayload($contact->id, $address->id);

    $this->postJson('/api/v1/laboratory-appointments', $payload, authHeaders($token))->assertCreated();
    expect(ApiV1AuditEvent::query()->count())->toBe(1);

    $this->postJson('/api/v1/laboratory-appointments', $payload, authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'APPOINTMENT_ALREADY_EXISTS');

    expect(ApiV1AuditEvent::query()->count())->toBe(2);
    $event = apptAuditLatest(AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED);
    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('APPOINTMENT_ALREADY_EXISTS')
        ->and($event->resource_key)->toBeNull();
});

test('POST foreign contact audits CONTACT_NOT_FOUND without foreign ids', function () {
    enableAppointmentConciergeAudit();
    [$owner, $ownerToken] = apptAuditCustomerToken();
    [$other] = apptAuditCustomerToken();
    apptAuditSetupRequiredCart($owner);
    [$otherContact, $otherAddress] = apptAuditSetupRequiredCart($other);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($otherContact->id, $otherAddress->id),
        authHeaders($ownerToken),
    )->assertNotFound()->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');

    $event = apptAuditLatest(AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED);

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('CONTACT_NOT_FOUND')
        ->and($event->resource_key)->toBeNull()
        ->and($event->metadata)->not->toHaveKey('appointment_row_id')
        ->and($event->metadata)->not->toHaveKey('contact_id')
        ->and($event->customer_id)->toBe($owner->customer->id)
        ->and($event->customer_id)->not->toBe($other->customer->id)
        ->and(($event->metadata['appointment_row_id'] ?? null))->not->toBe($otherContact->id);
});

test('POST foreign address audits ADDRESS_NOT_FOUND without foreign address id', function () {
    enableAppointmentConciergeAudit();
    [$owner, $ownerToken] = apptAuditCustomerToken();
    [$other] = apptAuditCustomerToken();
    [$contact] = apptAuditSetupRequiredCart($owner);
    [, $otherAddress] = apptAuditSetupRequiredCart($other);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($contact->id, $otherAddress->id),
        authHeaders($ownerToken),
    )->assertNotFound()->assertJsonPath('error.code', 'ADDRESS_NOT_FOUND');

    $event = apptAuditLatest(AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED);

    expect($event->error_code)->toBe('ADDRESS_NOT_FOUND')
        ->and($event->resource_key)->toBeNull()
        ->and($event->metadata)->not->toHaveKey('appointment_row_id')
        ->and($event->metadata)->not->toHaveKey('address_id')
        ->and($event->customer_id)->toBe($owner->customer->id)
        ->and(($event->metadata['appointment_row_id'] ?? null))->not->toBe($otherAddress->id);
});

test('DELETE foreign appointment audits APPOINTMENT_NOT_FOUND without foreign id', function () {
    enableAppointmentConciergeAudit();
    [$owner, $ownerToken] = apptAuditCustomerToken();
    [$other] = apptAuditCustomerToken();
    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $other->customer->id,
        'brand' => LaboratoryBrand::OLAB,
    ]);

    $this->deleteJson(
        "/api/v1/laboratory-appointments/{$appointment->id}",
        [],
        authHeaders($ownerToken),
    )->assertNotFound()->assertJsonPath('error.code', 'APPOINTMENT_NOT_FOUND');

    $event = apptAuditLatest(AuditEventDefinitions::EVENT_APPOINTMENTS_CANCELLED);

    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('APPOINTMENT_NOT_FOUND')
        ->and($event->resource_key)->toBeNull()
        ->and($event->metadata)->not->toHaveKey('appointment_row_id')
        ->and($event->customer_id)->toBe($owner->customer->id)
        ->and($event->customer_id)->not->toBe($other->customer->id)
        ->and(($event->resource_key))->not->toBe((string) $appointment->id)
        ->and(LaboratoryAppointment::query()->find($appointment->id))->not->toBeNull();
});

test('DELETE missing appointment audits APPOINTMENT_NOT_FOUND', function () {
    enableAppointmentConciergeAudit();
    [, $token] = apptAuditCustomerToken();

    $this->deleteJson('/api/v1/laboratory-appointments/999999001', [], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'APPOINTMENT_NOT_FOUND');

    $event = apptAuditLatest(AuditEventDefinitions::EVENT_APPOINTMENTS_CANCELLED);
    expect($event->outcome)->toBe(AuditOutcome::REJECTED)
        ->and($event->error_code)->toBe('APPOINTMENT_NOT_FOUND')
        ->and($event->resource_key)->toBeNull()
        ->and($event->metadata)->not->toHaveKey('appointment_row_id');
});

test('past scheduled_at VALIDATION_ERROR produces zero appointment audit events', function () {
    enableAppointmentConciergeAudit();
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($contact->id, $address->id, [
            'scheduled_at' => now()->subDay()->toIso8601String(),
        ]),
        authHeaders($token),
    )->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});

test('401 and 403 before controller produce zero appointment audit events', function () {
    enableAppointmentConciergeAudit();

    $this->postJson('/api/v1/laboratory-appointments', [
        'brand' => 'olab',
    ])->assertUnauthorized();

    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->postJson('/api/v1/laboratory-appointments', [
        'brand' => 'olab',
        'contact_id' => 1,
        'address_id' => 1,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ], authHeaders($token))->assertForbidden();

    expect(ApiV1AuditEvent::query()->count())->toBe(0);
});

// ── Privacy / normalizer ─────────────────────────────────────────────────

test('appointment metadata allowlist keeps safe keys and drops secrets notes and phone', function () {
    $normalizer = new AuditMetadataNormalizer(2048, 2);
    $result = $normalizer->normalize(AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED, [
        'laboratory_brand' => 'olab',
        'appointment_row_id' => 42,
        'appointment_state' => 'pending',
        'scheduling_mode' => 'callback_window',
        'request_channel' => 'akubica_api',
        'requested_date' => '2026-08-10',
        'requested_window' => 'one_hour',
        'timezone' => 'UTC',
        'checkout_draft_advanced' => true,
        'notes' => 'texto libre paciente',
        'phone' => '8181112233',
        'email' => 'x@ejemplo.com',
        'patient_name' => 'Juan',
        'callback_notes' => 'llamar por la tarde',
        'authorization' => 'Bearer secret',
        'idempotency_key' => 'idem-key-x',
        'unexpected_enum' => 'not-a-real-mode',
    ]);

    expect($result)->toBe([
        'laboratory_brand' => 'olab',
        'appointment_row_id' => 42,
        'appointment_state' => 'pending',
        'scheduling_mode' => 'callback_window',
        'request_channel' => 'akubica_api',
        'requested_date' => '2026-08-10',
        'requested_window' => 'one_hour',
        'timezone' => 'UTC',
        'checkout_draft_advanced' => true,
    ])
        ->and($result)->not->toHaveKey('notes')
        ->and($result)->not->toHaveKey('phone')
        ->and($result)->not->toHaveKey('unexpected_enum');
});

test('controlled state helper discards unexpected appointment_state values via recorder path', function () {
    enableAppointmentConciergeAudit();
    [$user, , $tokenId] = apptAuditCustomerToken();
    $pat = $user->tokens()->where('id', $tokenId)->first();
    expect($pat)->not->toBeNull();
    $user->withAccessToken($pat);

    $request = Request::create('/api/v1/laboratory-appointments', 'POST');
    $request->setUserResolver(fn () => $user);

    app(AppointmentConciergeAuditRecorder::class)->recordAppointmentRequested(
        request: $request,
        outcome: AuditOutcome::SUCCEEDED,
        httpStatus: 201,
        resourceKey: '99',
        laboratoryBrand: 'olab',
        appointmentRowId: 99,
        appointmentState: 'attended_by_agent',
        checkoutDraftAdvanced: true,
    );

    $event = apptAuditLatest(AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED);
    expect($event)->not->toBeNull()
        ->and($event->metadata)->not->toHaveKey('appointment_state')
        ->and($event->metadata['scheduling_mode'])->toBe('callback_window');
});

// ── Fail-soft ────────────────────────────────────────────────────────────

test('broken audit writer does not change appointment create outcome', function () {
    enableAppointmentConciergeAudit();
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);

    Schema::rename('api_v1_audit_events', 'api_v1_audit_events_broken');

    try {
        $this->postJson(
            '/api/v1/laboratory-appointments',
            apptAuditPayload($contact->id, $address->id),
            authHeaders($token),
        )->assertCreated()->assertJsonPath('success', true);

        expect(
            LaboratoryAppointment::query()
                ->where('customer_id', $user->customer->id)
                ->exists()
        )->toBeTrue();
    } finally {
        if (Schema::hasTable('api_v1_audit_events_broken')) {
            Schema::rename('api_v1_audit_events_broken', 'api_v1_audit_events');
        }
    }
});

test('broken audit writer does not change appointment rejection or cancel', function () {
    enableAppointmentConciergeAudit();
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);
    LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->delete();

    $appointment = LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
    ]);

    Schema::rename('api_v1_audit_events', 'api_v1_audit_events_broken');

    try {
        $this->postJson(
            '/api/v1/laboratory-appointments',
            apptAuditPayload($contact->id, $address->id),
            authHeaders($token),
        )->assertStatus(409)->assertJsonPath('error.code', 'EMPTY_CART');

        $this->deleteJson(
            "/api/v1/laboratory-appointments/{$appointment->id}",
            [],
            authHeaders($token),
        )->assertOk()->assertJsonPath('data.deleted', true);

        expect(LaboratoryAppointment::query()->find($appointment->id))->toBeNull();
    } finally {
        if (Schema::hasTable('api_v1_audit_events_broken')) {
            Schema::rename('api_v1_audit_events_broken', 'api_v1_audit_events');
        }
    }
});

// ── Idempotency ──────────────────────────────────────────────────────────

test('idempotent appointment create emits one audit event and replay emits zero extra', function () {
    enableAppointmentConciergeAudit();
    config()->set('api_v1.idempotency.enabled', true);
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);
    $payload = apptAuditPayload($contact->id, $address->id, ['notes' => null]);
    $key = 'idem-key-appt-block5-abcdef';
    $headers = array_merge(authHeaders($token), [
        IdempotencyKey::HEADER => $key,
        AkubicaCorrelationId::HEADER => 'corr-appt-idem-01',
    ]);

    $this->postJson('/api/v1/laboratory-appointments', $payload, $headers)->assertCreated();
    $this->postJson('/api/v1/laboratory-appointments', $payload, $headers)
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect(LaboratoryAppointment::query()->where('customer_id', $user->customer->id)->count())->toBe(1)
        ->and(ApiV1AuditEvent::query()
            ->where('event_name', AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED)
            ->count())->toBe(1);

    apptAuditAssertNoSecrets();
});

test('idempotent appointment conflict does not invent semantic audit beyond original', function () {
    enableAppointmentConciergeAudit();
    config()->set('api_v1.idempotency.enabled', true);
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);
    $key = 'idem-key-appt-conflict-99xx';
    $headers = array_merge(authHeaders($token), [IdempotencyKey::HEADER => $key]);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($contact->id, $address->id, ['notes' => null]),
        $headers,
    )->assertCreated();

    $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($contact->id, $address->id, [
            'notes' => null,
            'scheduled_at' => now()->addDays(5)->toIso8601String(),
        ]),
        $headers,
    )->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

    expect(ApiV1AuditEvent::query()
        ->where('event_name', AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED)
        ->count())->toBe(1);
});

// ── Jobs unchanged ───────────────────────────────────────────────────────

test('appointment create does not invent call or notification delivery in audit', function () {
    enableAppointmentConciergeAudit();
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);

    // Pre-existing domain may enqueue Scout/AC jobs on cart/contact; audit must not claim delivery.
    $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($contact->id, $address->id),
        authHeaders($token),
    )->assertCreated();

    $event = apptAuditLatest(AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED);
    $blob = json_encode($event->toArray());

    expect($event->metadata)->not->toHaveKey('job_dispatch_state')
        ->and($event->metadata)->not->toHaveKey('notification_pending')
        ->and($blob)->not->toContain('delivered')
        ->and($blob)->not->toContain('"called"')
        ->and($blob)->not->toContain('callback_completed');
});

// ── One terminal event per request ───────────────────────────────────────

test('single request produces exactly one terminal appointment audit event', function () {
    enableAppointmentConciergeAudit();
    [$user, $token] = apptAuditCustomerToken();
    [$contact, $address] = apptAuditSetupRequiredCart($user);

    $this->postJson(
        '/api/v1/laboratory-appointments',
        apptAuditPayload($contact->id, $address->id),
        authHeaders($token),
    )->assertCreated();

    expect(ApiV1AuditEvent::query()->count())->toBe(1);

    $appointmentId = LaboratoryAppointment::query()
        ->where('customer_id', $user->customer->id)
        ->value('id');

    $this->deleteJson("/api/v1/laboratory-appointments/{$appointmentId}", [], authHeaders($token))
        ->assertOk();

    expect(ApiV1AuditEvent::query()->count())->toBe(2)
        ->and(ApiV1AuditEvent::query()->where('event_name', AuditEventDefinitions::EVENT_APPOINTMENTS_REQUESTED)->count())->toBe(1)
        ->and(ApiV1AuditEvent::query()->where('event_name', AuditEventDefinitions::EVENT_APPOINTMENTS_CANCELLED)->count())->toBe(1);
});
