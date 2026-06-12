<?php

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Address;
use App\Models\AkubicaCheckoutLink;
use App\Models\Contact;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use Illuminate\Support\Str;

function setupReadyCheckout(User $user, LaboratoryBrand $brand = LaboratoryBrand::OLAB): array
{
    $test = createOlabTest([
        'brand' => $brand,
        'requires_appointment' => false,
        'famedic_price_cents' => 35000,
        'public_price_cents' => 45000,
    ]);

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

    LaboratoryCheckoutDraft::query()->updateOrCreate(
        [
            'customer_id' => $user->customer->id,
            'laboratory_brand' => $brand,
        ],
        [
            'contact_id' => $contact->id,
            'address_id' => $address->id,
            'checkout_step' => 'confirmation',
        ],
    );

    return [$contact, $address];
}

function createAkubicaCheckoutLink(User $user, string $plainToken, LaboratoryBrand $brand = LaboratoryBrand::OLAB, ?\Carbon\Carbon $expiresAt = null): AkubicaCheckoutLink
{
    return AkubicaCheckoutLink::query()->create([
        'customer_id' => $user->customer->id,
        'token_hash' => hash('sha256', $plainToken),
        'laboratory_brand' => $brand,
        'expires_at' => $expiresAt ?? now()->addHour(),
    ]);
}

// ── Auth ──────────────────────────────────────────────────────────────

test('POST /checkout/payment-link without token returns 401 UNAUTHENTICATED', function () {
    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('POST /checkout/payment-link with user without customer returns 403 FORBIDDEN', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

// ── Validación ────────────────────────────────────────────────────────

test('POST /checkout/payment-link without brand returns 422 VALIDATION_ERROR', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/checkout/payment-link', [], authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('POST /checkout/payment-link with invalid brand returns 422 VALIDATION_ERROR', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'invalid'], authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('POST /checkout/payment-link with expires_in_minutes below 5 returns 422', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/checkout/payment-link', [
        'brand' => 'olab',
        'expires_in_minutes' => 4,
    ], authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('POST /checkout/payment-link with expires_in_minutes above 1440 returns 422', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/checkout/payment-link', [
        'brand' => 'olab',
        'expires_in_minutes' => 1441,
    ], authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

// ── Reglas de negocio ─────────────────────────────────────────────────

test('POST /checkout/payment-link with empty cart returns 409 EMPTY_CART', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EMPTY_CART');
});

test('POST /checkout/payment-link with cart but no draft returns 409 CHECKOUT_NOT_READY', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CHECKOUT_NOT_READY')
        ->assertJsonPath('error.details.missing', ['contact_id', 'address_id']);
});

test('POST /checkout/payment-link with draft missing contact_id returns 409 CHECKOUT_NOT_READY', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    $address = Address::factory()->for($user->customer)->create();

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'address_id' => $address->id,
        'checkout_step' => 'address',
    ]);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CHECKOUT_NOT_READY')
        ->assertJsonPath('error.details.missing', ['contact_id']);
});

test('POST /checkout/payment-link with draft missing address_id returns 409 CHECKOUT_NOT_READY', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);

    $contact = Contact::factory()->for($user->customer)->create();

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'checkout_step' => 'address',
    ]);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CHECKOUT_NOT_READY')
        ->assertJsonPath('error.details.missing', ['address_id']);
});

test('POST /checkout/payment-link when appointment required and missing returns 409 APPOINTMENT_REQUIRED', function () {
    [$user, $token] = akubicaCustomerToken();

    $test = createOlabTest(['requires_appointment' => true]);
    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    $contact = Contact::factory()->for($user->customer)->create();
    $address = Address::factory()->for($user->customer)->create();

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'confirmation',
    ]);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'APPOINTMENT_REQUIRED');
});

test('POST /checkout/payment-link with ready checkout returns 200 with payment_link url', function () {
    [$user, $token] = akubicaCustomerToken();
    setupReadyCheckout($user);

    $response = $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.payment_link.brand', 'olab')
        ->assertJsonPath('data.payment_link.is_ready', true)
        ->assertJsonStructure([
            'data' => [
                'payment_link' => ['url', 'expires_at', 'expires_in_seconds', 'brand', 'is_ready'],
            ],
        ]);

    expect($response->json('data.payment_link.url'))
        ->toContain('/akubica/checkout/');
});

test('POST /checkout/payment-link does not create LaboratoryPurchase', function () {
    [$user, $token] = akubicaCustomerToken();
    setupReadyCheckout($user);

    $countBefore = LaboratoryPurchase::query()->count();

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertOk();

    expect(LaboratoryPurchase::query()->count())->toBe($countBefore);
});

test('POST /checkout/payment-link stores token hash not plain token', function () {
    [$user, $token] = akubicaCustomerToken();
    setupReadyCheckout($user);

    $response = $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertOk();

    $url = $response->json('data.payment_link.url');
    $plainToken = Str::afterLast($url, '/akubica/checkout/');

    $record = AkubicaCheckoutLink::query()->first();

    expect($record->token_hash)->toBe(hash('sha256', $plainToken))
        ->and(json_encode($record->toArray()))->not->toContain($plainToken);
});

test('POST /checkout/payment-link generates unique token hashes', function () {
    [$user, $token] = akubicaCustomerToken();
    setupReadyCheckout($user);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))->assertOk();
    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))->assertOk();

    $hashes = AkubicaCheckoutLink::query()->pluck('token_hash');

    expect($hashes)->toHaveCount(2)
        ->and($hashes->unique())->toHaveCount(2);
});

// ── Web link ──────────────────────────────────────────────────────────

test('GET akubica checkout link with valid token redirects to laboratory checkout', function () {
    [$user] = akubicaCustomerToken();
    [$contact, $address] = setupReadyCheckout($user);

    $plainToken = Str::random(64);
    createAkubicaCheckoutLink($user, $plainToken);

    $this->get("/akubica/checkout/{$plainToken}")
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'step' => 'confirmation',
            'contact' => $contact->id,
            'address' => $address->id,
        ]));

    $this->assertAuthenticatedAs($user);
});

test('GET akubica checkout link with expired token redirects to login', function () {
    [$user] = akubicaCustomerToken();
    setupReadyCheckout($user);

    $plainToken = Str::random(64);
    createAkubicaCheckoutLink($user, $plainToken, LaboratoryBrand::OLAB, now()->subMinute());

    $this->get("/akubica/checkout/{$plainToken}")
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('GET akubica checkout link with invalid token redirects to login', function () {
    $this->get('/akubica/checkout/invalid-token-value')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('GET akubica checkout link does not authenticate another customer', function () {
    [$owner] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    setupReadyCheckout($owner);

    $plainToken = Str::random(64);
    createAkubicaCheckoutLink($owner, $plainToken);

    $this->get("/akubica/checkout/{$plainToken}")
        ->assertRedirect();

    $this->assertAuthenticatedAs($owner);
    expect(auth()->id())->not->toBe($other->id);
});

test('GET akubica checkout link with empty cart redirects to login with error', function () {
    [$user] = akubicaCustomerToken();
    setupReadyCheckout($user);

    $plainToken = Str::random(64);
    createAkubicaCheckoutLink($user, $plainToken);

    LaboratoryCartItem::query()->where('customer_id', $user->customer->id)->delete();

    $this->get("/akubica/checkout/{$plainToken}")
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');
});

test('GET akubica checkout link with deleted draft redirects to login with error', function () {
    [$user] = akubicaCustomerToken();
    setupReadyCheckout($user);

    $plainToken = Str::random(64);
    createAkubicaCheckoutLink($user, $plainToken);

    LaboratoryCheckoutDraft::query()->where('customer_id', $user->customer->id)->delete();

    $this->get("/akubica/checkout/{$plainToken}")
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');
});

test('GET akubica checkout link with pending appointment allows redirect when appointment exists', function () {
    [$user] = akubicaCustomerToken();

    $test = createOlabTest(['requires_appointment' => true]);
    LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);

    $contact = Contact::factory()->for($user->customer)->create();
    $address = Address::factory()->for($user->customer)->create();

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
        'checkout_step' => 'appointment',
    ]);

    LaboratoryAppointment::factory()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB,
        'confirmed_at' => null,
    ]);

    $plainToken = Str::random(64);
    createAkubicaCheckoutLink($user, $plainToken);

    $this->get("/akubica/checkout/{$plainToken}")
        ->assertRedirect(route('laboratory.checkout', [
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'step' => 'appointment',
            'contact' => $contact->id,
            'address' => $address->id,
        ]));
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
