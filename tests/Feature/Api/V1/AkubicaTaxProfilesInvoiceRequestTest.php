<?php

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\TaxProfile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

function validTaxProfilePayload(array $overrides = []): array
{
    return array_merge([
        'rfc' => 'XAXX010101000',
        'business_name' => 'PUBLICO EN GENERAL',
        'tax_regime' => '616',
        'cfdi_use' => 'S01',
        'postal_code' => '64000',
        'email' => 'facturacion@example.com',
    ], $overrides);
}

function createAkubicaTaxProfile(User $user, array $attributes = []): TaxProfile
{
    return TaxProfile::factory()->for($user->customer)->create(array_merge([
        'name' => 'PUBLICO EN GENERAL',
        'razon_social' => 'PUBLICO EN GENERAL',
        'rfc' => 'XAXX010101000',
        'zipcode' => '64000',
        'tax_regime' => '616',
        'cfdi_use' => 'S01',
    ], $attributes));
}

function createInvoiceableAkubicaOrder(User $user, array $attributes = []): LaboratoryPurchase
{
    return LaboratoryPurchase::query()->create(array_merge([
        'customer_id' => $user->customer->id,
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
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

function sprint12Invoice(LaboratoryPurchase $purchase): Invoice
{
    return Invoice::query()->create([
        'invoiceable_type' => LaboratoryPurchase::class,
        'invoiceable_id' => $purchase->id,
        'invoice' => 'invoices/test-invoice.pdf',
    ]);
}

function sprint12InvoiceRequest(LaboratoryPurchase $purchase): InvoiceRequest
{
    return InvoiceRequest::query()->create([
        'invoice_requestable_type' => LaboratoryPurchase::class,
        'invoice_requestable_id' => $purchase->id,
        'name' => 'PUBLICO EN GENERAL',
        'rfc' => 'XAXX010101000',
        'zipcode' => '64000',
        'tax_regime' => '616',
        'cfdi_use' => 'S01',
        'fiscal_certificate' => 'certificates/test.cer',
    ]);
}

// ── Auth ──────────────────────────────────────────────────────────────

test('POST /user/tax-profiles without token returns 401 UNAUTHENTICATED', function () {
    $this->postJson('/api/v1/user/tax-profiles', validTaxProfilePayload())
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('POST /orders/{id}/invoice-request without token returns 401 UNAUTHENTICATED', function () {
    $this->postJson('/api/v1/orders/1/invoice-request', ['tax_profile_id' => 1])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('POST /user/tax-profiles with user without customer returns 403 FORBIDDEN', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->postJson('/api/v1/user/tax-profiles', validTaxProfilePayload(), authHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

// ── Tax profiles ──────────────────────────────────────────────────────

test('GET /user/tax-profiles still works after Sprint 12', function () {
    [$user, $token] = akubicaCustomerToken();
    createAkubicaTaxProfile($user);

    $this->getJson('/api/v1/user/tax-profiles', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.tax_profiles')
        ->assertJsonPath('data.tax_profiles.0.business_name', 'PUBLICO EN GENERAL');
});

test('POST /user/tax-profiles with valid payload returns 201', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/user/tax-profiles', validTaxProfilePayload(), authHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.tax_profile.rfc', 'XAXX010101000')
        ->assertJsonPath('data.tax_profile.business_name', 'PUBLICO EN GENERAL')
        ->assertJsonPath('data.tax_profile.tax_regime', '616')
        ->assertJsonPath('data.tax_profile.cfdi_use', 'S01')
        ->assertJsonPath('data.tax_profile.postal_code', '64000');
});

test('POST /user/tax-profiles with invalid payload returns 422 VALIDATION_ERROR', function () {
    [, $token] = akubicaCustomerToken();

    $this->postJson('/api/v1/user/tax-profiles', [
        'rfc' => 'INVALID',
        'business_name' => '',
    ], authHeaders($token))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

test('PUT /user/tax-profiles/{id} updates own profile', function () {
    [$user, $token] = akubicaCustomerToken();
    $profile = createAkubicaTaxProfile($user);

    $this->putJson(
        "/api/v1/user/tax-profiles/{$profile->id}",
        validTaxProfilePayload([
            'business_name' => 'EMPRESA ACTUALIZADA SA',
            'rfc' => 'XAXX010101000',
        ]),
        authHeaders($token),
    )
        ->assertOk()
        ->assertJsonPath('data.tax_profile.business_name', 'EMPRESA ACTUALIZADA SA');
});

test('PUT /user/tax-profiles/{id} for another customer returns 404 TAX_PROFILE_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $otherProfile = createAkubicaTaxProfile($other, ['rfc' => 'XEXX010101000']);

    $this->putJson(
        "/api/v1/user/tax-profiles/{$otherProfile->id}",
        validTaxProfilePayload(['rfc' => 'XEXX010101000']),
        authHeaders($ownerToken),
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'TAX_PROFILE_NOT_FOUND');
});

test('DELETE /user/tax-profiles/{id} deletes own profile', function () {
    [$user, $token] = akubicaCustomerToken();
    $profile = createAkubicaTaxProfile($user);

    $this->deleteJson("/api/v1/user/tax-profiles/{$profile->id}", [], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(TaxProfile::query()->find($profile->id))->toBeNull();
});

test('DELETE /user/tax-profiles/{id} for another customer returns 404 TAX_PROFILE_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $otherProfile = createAkubicaTaxProfile($other, ['rfc' => 'XEXX010101000']);

    $this->deleteJson("/api/v1/user/tax-profiles/{$otherProfile->id}", [], authHeaders($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'TAX_PROFILE_NOT_FOUND');
});

test('DELETE /user/tax-profiles/{id} when not exists returns 404 TAX_PROFILE_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();

    $this->deleteJson('/api/v1/user/tax-profiles/999999', [], authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'TAX_PROFILE_NOT_FOUND');
});

// ── Invoice request ───────────────────────────────────────────────────

test('POST /orders/{id}/invoice-request for nonexistent order returns 404 ORDER_NOT_FOUND', function () {
    [$user, $token] = akubicaCustomerToken();
    $profile = createAkubicaTaxProfile($user);

    $this->postJson(
        '/api/v1/orders/999999/invoice-request',
        ['tax_profile_id' => $profile->id],
        authHeaders($token),
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('POST /orders/{id}/invoice-request for another customer order returns 404 ORDER_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $otherOrder = createInvoiceableAkubicaOrder($other);
    $ownerProfile = createAkubicaTaxProfile($owner);

    $this->postJson(
        "/api/v1/orders/{$otherOrder->id}/invoice-request",
        ['tax_profile_id' => $ownerProfile->id],
        authHeaders($ownerToken),
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('POST /orders/{id}/invoice-request with foreign tax profile returns 404 TAX_PROFILE_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $order = createInvoiceableAkubicaOrder($owner);
    $otherProfile = createAkubicaTaxProfile($other, ['rfc' => 'XEXX010101000']);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoice-request",
        ['tax_profile_id' => $otherProfile->id],
        authHeaders($ownerToken),
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'TAX_PROFILE_NOT_FOUND');
});

test('POST /orders/{id}/invoice-request for non-invoiceable order returns 409 ORDER_NOT_INVOICEABLE', function () {
    [$user, $token] = akubicaCustomerToken();
    $profile = createAkubicaTaxProfile($user);

    $order = createInvoiceableAkubicaOrder($user, [
        'created_at' => now()->subMonths(2),
        'updated_at' => now()->subMonths(2),
    ]);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoice-request",
        ['tax_profile_id' => $profile->id],
        authHeaders($token),
    )
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ORDER_NOT_INVOICEABLE');
});

test('POST /orders/{id}/invoice-request with valid payload returns 201', function () {
    [$user, $token] = akubicaCustomerToken();
    $profile = createAkubicaTaxProfile($user);
    $order = createInvoiceableAkubicaOrder($user);

    Storage::fake();
    Storage::put('fiscal-certificates/test/cert.pdf', 'pdf-content');
    $profile->update(['fiscal_certificate' => 'fiscal-certificates/test/cert.pdf']);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoice-request",
        [
            'tax_profile_id' => $profile->id,
            'cfdi_use' => 'G03',
            'notes' => 'Solicitada desde asistente Akubica',
        ],
        authHeaders($token),
    )
        ->assertCreated()
        ->assertJsonPath('data.invoice_request.order_id', $order->id)
        ->assertJsonPath('data.invoice_request.tax_profile_id', $profile->id)
        ->assertJsonPath('data.invoice_request.status', 'pending');
});

test('POST /orders/{id}/invoice-request duplicate returns 409 INVOICE_REQUEST_ALREADY_EXISTS', function () {
    [$user, $token] = akubicaCustomerToken();
    $profile = createAkubicaTaxProfile($user);
    $order = createInvoiceableAkubicaOrder($user);

    sprint12InvoiceRequest($order);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoice-request",
        ['tax_profile_id' => $profile->id],
        authHeaders($token),
    )
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVOICE_REQUEST_ALREADY_EXISTS');
});

test('POST /orders/{id}/invoice-request when invoice already exists returns 409 INVOICE_ALREADY_EXISTS', function () {
    [$user, $token] = akubicaCustomerToken();
    $profile = createAkubicaTaxProfile($user);
    $order = createInvoiceableAkubicaOrder($user);

    sprint12Invoice($order);

    $this->postJson(
        "/api/v1/orders/{$order->id}/invoice-request",
        ['tax_profile_id' => $profile->id],
        authHeaders($token),
    )
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVOICE_ALREADY_EXISTS');
});

test('GET /orders/{id}/invoice-request/status without request returns not_requested', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createInvoiceableAkubicaOrder($user);

    $this->getJson("/api/v1/orders/{$order->id}/invoice-request/status", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.order_id', $order->id)
        ->assertJsonPath('data.invoice_status', 'not_requested')
        ->assertJsonPath('data.invoice_request', null)
        ->assertJsonPath('data.invoice', null);
});

test('GET /orders/{id}/invoice-request/status with pending request returns pending', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createInvoiceableAkubicaOrder($user);
    $request = sprint12InvoiceRequest($order);

    $this->getJson("/api/v1/orders/{$order->id}/invoice-request/status", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.invoice_status', 'pending')
        ->assertJsonPath('data.invoice_request.id', $request->id)
        ->assertJsonPath('data.invoice', null);
});

test('GET /orders/{id}/invoice-request/status with issued invoice returns issued', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createInvoiceableAkubicaOrder($user);
    $invoice = sprint12Invoice($order);

    $this->getJson("/api/v1/orders/{$order->id}/invoice-request/status", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.invoice_status', 'issued')
        ->assertJsonPath('data.invoice.id', $invoice->id)
        ->assertJsonPath('data.invoice.status', 'issued')
        ->assertJsonStructure(['data' => ['invoice' => ['download_url']]]);
});

test('GET /orders/{id}/invoice-request/status for another customer order returns 404 ORDER_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $otherOrder = createInvoiceableAkubicaOrder($other);

    $this->getJson("/api/v1/orders/{$otherOrder->id}/invoice-request/status", authHeaders($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

// ── Regresión ─────────────────────────────────────────────────────────

test('GET /orders/{id}/invoices still works after Sprint 12', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createInvoiceableAkubicaOrder($user);
    $invoice = sprint12Invoice($order);

    $this->getJson("/api/v1/orders/{$order->id}/invoices", authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.invoices')
        ->assertJsonPath('data.invoices.0.id', $invoice->id)
        ->assertJsonPath('data.invoices.0.status', 'issued');
});

test('GET /orders/invoices still works after Sprint 12', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createInvoiceableAkubicaOrder($user);
    sprint12Invoice($order);

    $this->getJson('/api/v1/orders/invoices', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.invoices');
});

test('POST /checkout/payment-link still works after Sprint 12', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user, createOlabTest(['requires_appointment' => false]));

    $contact = \App\Models\Contact::factory()->for($user->customer)->create([
        'birth_date' => '1990-01-01',
        'gender' => \App\Enums\Gender::MALE,
    ]);
    $address = \App\Models\Address::factory()->for($user->customer)->create();

    LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => \App\Enums\LaboratoryBrand::OLAB,
        'contact_id' => $contact->id,
        'address_id' => $address->id,
    ]);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.payment_link.is_ready', true);
});

test('GET /catalog/medications/{id} still returns 503 CATALOG_UNAVAILABLE', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/medications/1', authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'CATALOG_UNAVAILABLE');
});

test('PUT /orders/{id}/cancel still returns 503 FEATURE_DISABLED', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createInvoiceableAkubicaOrder($user);

    $this->putJson("/api/v1/orders/{$order->id}/cancel", [], authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');
});
