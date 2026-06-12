<?php

use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

// ── Auth ────────────────────────────────────────────────────────────────

test('GET result download without token returns 401', function () {
    $this->get('/api/v1/orders/1/results/download')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('GET invoice download without token returns 401', function () {
    $this->get('/api/v1/orders/1/invoices/1/download')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('document download endpoints return 403 for user without customer', function () {
    $user = User::factory()->create();
    $token = $user->createToken('akubica-test')->plainTextToken;
    $headers = authHeaders($token);

    $this->get('/api/v1/orders/1/results/download', $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');

    $this->get('/api/v1/orders/1/invoices/1/download', $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

// ── Results download ────────────────────────────────────────────────────

test('result download for nonexistent order returns 404 ORDER_NOT_FOUND', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/orders/99999/results/download', authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('result download for another customer order returns 404 ORDER_NOT_FOUND', function () {
    [$userA, $tokenA] = akubicaCustomerToken();
    [$userB] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($userB);

    $this->getJson("/api/v1/orders/{$order->id}/results/download", authHeaders($tokenA))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('result download for order without results returns 409 RESULT_NOT_READY', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $this->getJson("/api/v1/orders/{$order->id}/results/download", authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'RESULT_NOT_READY');
});

test('result download with manual storage PDF returns 200 application/pdf', function () {
    [$user, $token] = akubicaCustomerToken();
    $path = 'results/manual-100.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $response = $this->get("/api/v1/orders/{$order->id}/results/download", authHeaders($token));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="resultado-'.$order->id.'.pdf"');

    expect($response->getContent())->toStartWith('%PDF');
});

test('result download with cached GDA notification returns 200 application/pdf', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    createAkubicaResultsNotification($order, '%PDF-1.4 gda cached results');

    $response = $this->get("/api/v1/orders/{$order->id}/results/download", authHeaders($token));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF');
});

test('result download does not expose base64 or internal paths in response', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $pdfContent = '%PDF-1.4 secret results content';
    createAkubicaResultsNotification($order, $pdfContent);

    $response = $this->get("/api/v1/orders/{$order->id}/results/download", authHeaders($token));

    $body = $response->getContent();
    expect($body)->not->toContain(base64_encode($pdfContent));
    expect($body)->not->toContain('results/');
    expect($body)->not->toContain('s3://');
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

test('result download response includes expected filename in Content-Disposition', function () {
    [$user, $token] = akubicaCustomerToken();
    $path = 'results/manual.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->get("/api/v1/orders/{$order->id}/results/download", authHeaders($token))
        ->assertHeader('Content-Disposition', 'inline; filename="resultado-'.$order->id.'.pdf"');
});

test('result download response includes secure Cache-Control header', function () {
    [$user, $token] = akubicaCustomerToken();
    $path = 'results/manual.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->get("/api/v1/orders/{$order->id}/results/download", authHeaders($token))
        ->assertHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate');
});

// ── Invoice download ────────────────────────────────────────────────────

test('invoice download for nonexistent order returns 404 ORDER_NOT_FOUND', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/orders/99999/invoices/1/download', authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('invoice download for another customer order returns 404 ORDER_NOT_FOUND', function () {
    [$userA, $tokenA] = akubicaCustomerToken();
    [$userB] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($userB);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->getJson("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", authHeaders($tokenA))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'ORDER_NOT_FOUND');
});

test('invoice download for nonexistent invoice returns 404 INVOICE_NOT_FOUND', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $this->getJson("/api/v1/orders/{$order->id}/invoices/99999/download", authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'INVOICE_NOT_FOUND');
});

test('invoice download for invoice belonging to another order returns 404 INVOICE_NOT_FOUND', function () {
    [$user, $token] = akubicaCustomerToken();
    $orderA = createAkubicaLaboratoryPurchase($user);
    $orderB = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($orderB);

    $this->getJson("/api/v1/orders/{$orderA->id}/invoices/{$invoice->id}/download", authHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'INVOICE_NOT_FOUND');
});

test('invoice download when PDF file is missing returns 409 INVOICE_NOT_READY', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = Invoice::query()->create([
        'invoiceable_type' => LaboratoryPurchase::class,
        'invoiceable_id' => $order->id,
        'invoice' => 'invoices/missing.pdf',
    ]);

    $this->getJson("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", authHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVOICE_NOT_READY');
});

test('invoice download with available PDF returns 200 application/pdf', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $response = $this->get("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", authHeaders($token));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF');
});

test('invoice download does not expose internal storage paths', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order, 'invoices/secret-path.pdf');

    $response = $this->get("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", authHeaders($token));

    expect($response->getContent())->not->toContain('invoices/secret-path.pdf');
    expect($response->getContent())->not->toContain('s3://');
});

test('invoice download response includes expected filename in Content-Disposition', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->get("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", authHeaders($token))
        ->assertHeader('Content-Disposition', 'inline; filename="factura-'.$invoice->id.'.pdf"');
});

test('invoice download response includes secure Cache-Control header', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->get("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download", authHeaders($token))
        ->assertHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate');
});

// ── Integración con resources ───────────────────────────────────────────

test('GET order results includes bearer download metadata', function () {
    [$user, $token] = akubicaCustomerToken();
    $path = 'results/manual.pdf';
    storeFakePdf($path);
    $order = createAkubicaLaboratoryPurchase($user, ['results' => $path]);

    $this->getJson("/api/v1/orders/{$order->id}/results", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.download.type', 'bearer')
        ->assertJsonPath('data.download.url', url("/api/v1/orders/{$order->id}/results/download"));
});

test('GET order results with GDA notification includes bearer download metadata', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    createAkubicaResultsNotification($order);

    $this->getJson("/api/v1/orders/{$order->id}/results", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.download.type', 'bearer')
        ->assertJsonPath('data.results.0.download.type', 'bearer');
});

test('GET order invoices includes bearer download metadata for issued invoice', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);
    $invoice = createAkubicaLaboratoryInvoice($order);

    $this->getJson("/api/v1/orders/{$order->id}/invoices", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.invoices.0.download.type', 'bearer')
        ->assertJsonPath('data.invoices.0.download.url', url("/api/v1/orders/{$order->id}/invoices/{$invoice->id}/download"));
});

// ── Regresión ───────────────────────────────────────────────────────────

test('payment link still works after document download endpoints', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    setupAkubicaCheckoutDraft($user);

    $this->postJson('/api/v1/checkout/payment-link', ['brand' => 'olab'], authHeaders($token))
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('cart coupon apply still works after document download endpoints', function () {
    [$user, $token] = akubicaCustomerToken();
    addOlabCartItem($user);
    createBalanceCouponForUser($user, 'PROMO10', 7000);

    $this->postJson('/api/v1/cart/coupon', [
        'brand' => 'olab',
        'code' => 'PROMO10',
    ], authHeaders($token))->assertOk();
});

test('invoice request status endpoint still responds for owned order', function () {
    [$user, $token] = akubicaCustomerToken();
    $order = createAkubicaLaboratoryPurchase($user);

    $this->getJson("/api/v1/orders/{$order->id}/invoice-request/status", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('medications catalog endpoint still returns 503', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->getJson('/api/v1/catalog/medications/1', authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'CATALOG_UNAVAILABLE');
});

test('order cancellation still returns 503', function () {
    [$user, $token] = akubicaCustomerToken();

    $this->putJson('/api/v1/orders/1/cancel', [], authHeaders($token))
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'FEATURE_DISABLED');
});
