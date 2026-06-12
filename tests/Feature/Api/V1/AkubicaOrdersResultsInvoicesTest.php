<?php

use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\User;

function createAkubicaInvoiceRequest(LaboratoryPurchase $purchase, array $attributes = []): InvoiceRequest
{
    return InvoiceRequest::query()->create(array_merge([
        'invoice_requestable_type' => LaboratoryPurchase::class,
        'invoice_requestable_id' => $purchase->id,
        'name' => 'PUBLICO EN GENERAL',
        'rfc' => 'XAXX010101000',
        'zipcode' => '64000',
        'tax_regime' => '616',
        'cfdi_use' => 'S01',
        'fiscal_certificate' => 'certificates/test.cer',
    ], $attributes));
}

// ── Auth ──────────────────────────────────────────────────────────────

test('GET /orders/results without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/orders/results')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /orders/invoices without token returns 401 UNAUTHENTICATED', function () {
    $this->getJson('/api/v1/orders/invoices')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET /orders/results with user without customer returns 403 FORBIDDEN', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;

    $this->getJson('/api/v1/orders/results', authHeaders($token))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

// ── Results index ─────────────────────────────────────────────────────

test('GET /orders/results with no results returns empty array', function () {
    [$user, $token] = akubicaCustomerToken();

    createAkubicaLaboratoryPurchase($user);

    $this->getJson('/api/v1/orders/results', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'results' => [],
                'pagination' => [
                    'current_page' => 1,
                    'total' => 0,
                ],
            ],
        ]);
});

test('GET /orders/results returns results with expected structure', function () {
    [$user, $token] = akubicaCustomerToken();

    $purchase = createAkubicaLaboratoryPurchase($user);
    LaboratoryPurchaseItem::query()->create([
        'laboratory_purchase_id' => $purchase->id,
        'gda_id' => 'GDA-001',
        'name' => 'Biometría hemática',
        'indications' => 'Ayuno',
        'price_cents' => 35000,
    ]);
    createAkubicaResultsNotification($purchase);

    $this->getJson('/api/v1/orders/results', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.results')
        ->assertJsonPath('data.results.0.order_id', $purchase->id)
        ->assertJsonPath('data.results.0.study_name', 'Biometría hemática')
        ->assertJsonPath('data.results.0.brand', 'olab')
        ->assertJsonPath('data.results.0.status', 'results_ready')
        ->assertJsonPath('data.results.0.results_available', true)
        ->assertJsonPath('data.results.0.has_pdf', true)
        ->assertJsonMissingPath('data.results.0.download_url')
        ->assertJsonMissingPath('data.results.0.results_pdf_base64');
});

test('GET /orders/results only returns results of authenticated customer', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $ownerPurchase = createAkubicaLaboratoryPurchase($owner);
    createAkubicaResultsNotification($ownerPurchase);

    $otherPurchase = createAkubicaLaboratoryPurchase($other);
    createAkubicaResultsNotification($otherPurchase);

    $this->getJson('/api/v1/orders/results', authHeaders($ownerToken))
        ->assertOk()
        ->assertJsonCount(1, 'data.results')
        ->assertJsonPath('data.results.0.order_id', $ownerPurchase->id);
});

test('GET /orders/results paginates correctly', function () {
    [$user, $token] = akubicaCustomerToken();

    for ($i = 0; $i < 3; $i++) {
        $purchase = createAkubicaLaboratoryPurchase($user);
        createAkubicaResultsNotification($purchase);
    }

    $this->getJson('/api/v1/orders/results?per_page=2&page=2', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.pagination.current_page', 2)
        ->assertJsonPath('data.pagination.per_page', 2)
        ->assertJsonPath('data.pagination.total', 3)
        ->assertJsonCount(1, 'data.results');
});

// ── Order results ─────────────────────────────────────────────────────

test('GET /orders/{id}/results for nonexistent order returns 404 ORDER_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/orders/999999/results', authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('GET /orders/{id}/results for another customer order returns 404 ORDER_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $otherPurchase = createAkubicaLaboratoryPurchase($other);
    createAkubicaResultsNotification($otherPurchase);

    $this->getJson("/api/v1/orders/{$otherPurchase->id}/results", authHeaders($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('GET /orders/{id}/results without results returns 404 RESULTS_NOT_AVAILABLE', function () {
    [$user, $token] = akubicaCustomerToken();
    $purchase = createAkubicaLaboratoryPurchase($user);

    $this->getJson("/api/v1/orders/{$purchase->id}/results", authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'RESULTS_NOT_AVAILABLE');
});

test('GET /orders/{id}/results with manual results returns expected structure', function () {
    [$user, $token] = akubicaCustomerToken();

    $purchase = createAkubicaLaboratoryPurchase($user, [
        'results' => 'laboratory-results/manual.pdf',
    ]);

    $this->getJson("/api/v1/orders/{$purchase->id}/results", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.order_id', $purchase->id)
        ->assertJsonPath('data.status', 'results_ready')
        ->assertJsonPath('data.results_available', true)
        ->assertJsonPath('data.has_pdf', true)
        ->assertJsonStructure(['data' => ['download_url']])
        ->assertJsonMissingPath('data.results_pdf_base64');
});

test('GET /orders/{id}/results with API notification returns expected structure', function () {
    [$user, $token] = akubicaCustomerToken();

    $purchase = createAkubicaLaboratoryPurchase($user);
    createAkubicaResultsNotification($purchase);

    $this->getJson("/api/v1/orders/{$purchase->id}/results", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.order_id', $purchase->id)
        ->assertJsonPath('data.status', 'results_ready')
        ->assertJsonPath('data.results_available', true)
        ->assertJsonCount(1, 'data.results')
        ->assertJsonPath('data.results.0.has_pdf', true)
        ->assertJsonStructure([
            'data' => [
                'results' => [[
                    'id',
                    'name',
                    'available_at',
                    'download_url',
                    'has_pdf',
                ]],
            ],
        ])
        ->assertJsonMissingPath('data.results.0.results_pdf_base64');
});

// ── Invoices index ────────────────────────────────────────────────────

test('GET /orders/invoices with no invoices returns empty array', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/orders/invoices', authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'invoices' => [],
                'pagination' => [
                    'current_page' => 1,
                    'total' => 0,
                ],
            ],
        ]);
});

test('GET /orders/invoices returns issued invoices with expected structure', function () {
    [$user, $token] = akubicaCustomerToken();

    $purchase = createAkubicaLaboratoryPurchase($user);
    LaboratoryPurchaseItem::query()->create([
        'laboratory_purchase_id' => $purchase->id,
        'gda_id' => 'GDA-002',
        'name' => 'Biometría hemática',
        'indications' => 'Ayuno',
        'price_cents' => 35000,
    ]);
    $invoice = createAkubicaLaboratoryInvoice($purchase);

    $this->getJson('/api/v1/orders/invoices', authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.invoices')
        ->assertJsonPath('data.invoices.0.id', $invoice->id)
        ->assertJsonPath('data.invoices.0.order_id', $purchase->id)
        ->assertJsonPath('data.invoices.0.status', 'issued')
        ->assertJsonPath('data.invoices.0.total_cents', 35000)
        ->assertJsonPath('data.invoices.0.order_study_name', 'Biometría hemática')
        ->assertJsonStructure(['data' => ['invoices' => [['issued_at', 'download_url']]]]);
});

test('GET /orders/invoices only returns invoices of authenticated customer', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $ownerPurchase = createAkubicaLaboratoryPurchase($owner);
    createAkubicaLaboratoryInvoice($ownerPurchase);

    $otherPurchase = createAkubicaLaboratoryPurchase($other);
    createAkubicaLaboratoryInvoice($otherPurchase);

    $this->getJson('/api/v1/orders/invoices', authHeaders($ownerToken))
        ->assertOk()
        ->assertJsonCount(1, 'data.invoices')
        ->assertJsonPath('data.invoices.0.order_id', $ownerPurchase->id);
});

test('GET /orders/invoices paginates correctly', function () {
    [$user, $token] = akubicaCustomerToken();

    for ($i = 0; $i < 3; $i++) {
        $purchase = createAkubicaLaboratoryPurchase($user);
        createAkubicaLaboratoryInvoice($purchase);
    }

    $this->getJson('/api/v1/orders/invoices?per_page=2&page=2', authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.pagination.current_page', 2)
        ->assertJsonPath('data.pagination.total', 3)
        ->assertJsonCount(1, 'data.invoices');
});

// ── Order invoices ────────────────────────────────────────────────────

test('GET /orders/{id}/invoices for nonexistent order returns 404 ORDER_NOT_FOUND', function () {
    [, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/orders/999999/invoices', authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('GET /orders/{id}/invoices for another customer order returns 404 ORDER_NOT_FOUND', function () {
    [$owner, $ownerToken] = akubicaCustomerToken();
    [$other] = akubicaCustomerToken();

    $otherPurchase = createAkubicaLaboratoryPurchase($other);
    createAkubicaLaboratoryInvoice($otherPurchase);

    $this->getJson("/api/v1/orders/{$otherPurchase->id}/invoices", authHeaders($ownerToken))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('GET /orders/{id}/invoices without invoices returns empty array', function () {
    [$user, $token] = akubicaCustomerToken();
    $purchase = createAkubicaLaboratoryPurchase($user);

    $this->getJson("/api/v1/orders/{$purchase->id}/invoices", authHeaders($token))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => [
                'order_id' => $purchase->id,
                'invoices' => [],
            ],
        ]);
});

test('GET /orders/{id}/invoices returns invoice with expected structure', function () {
    [$user, $token] = akubicaCustomerToken();
    $purchase = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($purchase);

    $this->getJson("/api/v1/orders/{$purchase->id}/invoices", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.order_id', $purchase->id)
        ->assertJsonCount(1, 'data.invoices')
        ->assertJsonPath('data.invoices.0.id', $invoice->id)
        ->assertJsonPath('data.invoices.0.status', 'issued')
        ->assertJsonPath('data.invoices.0.total_cents', 35000)
        ->assertJsonMissingPath('data.invoices.0.order_study_name');
});

test('GET /orders/{id}/invoices returns pending invoice request', function () {
    [$user, $token] = akubicaCustomerToken();
    $purchase = createAkubicaLaboratoryPurchase($user);
    $request = createAkubicaInvoiceRequest($purchase);

    $this->getJson("/api/v1/orders/{$purchase->id}/invoices", authHeaders($token))
        ->assertOk()
        ->assertJsonCount(1, 'data.invoices')
        ->assertJsonPath('data.invoices.0.id', $request->id)
        ->assertJsonPath('data.invoices.0.status', 'pending')
        ->assertJsonPath('data.invoices.0.download_url', null);
});

// ── Regresión 501 ─────────────────────────────────────────────────────

test('PUT /orders/{id}/cancel returns 503 FEATURE_DISABLED', function () {
    [$user, $token] = akubicaCustomerToken();
    $purchase = createAkubicaLaboratoryPurchase($user);

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
