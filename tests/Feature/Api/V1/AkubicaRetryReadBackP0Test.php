<?php

use App\Enums\LaboratoryBrand;
use App\Models\TaxProfile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

test('p0 retry read-back invoice request status reconciles a prior mutation', function () {
    [$user, $token] = akubicaCustomerToken();
    $certificatePath = 'fiscal-certificates/ti-06/cert.pdf';
    Storage::put($certificatePath, 'certificate-content');

    $profile = TaxProfile::factory()->for($user->customer)->create([
        'name' => 'PUBLICO EN GENERAL',
        'razon_social' => 'PUBLICO EN GENERAL',
        'rfc' => 'XAXX010101000',
        'zipcode' => '64000',
        'tax_regime' => '616',
        'cfdi_use' => 'S01',
        'fiscal_certificate' => $certificatePath,
    ]);

    $order = createAkubicaLaboratoryPurchase($user, [
        'brand' => LaboratoryBrand::OLAB,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $created = $this->postJson(
        "/api/v1/orders/{$order->id}/invoice-request",
        ['tax_profile_id' => $profile->id, 'cfdi_use' => 'G03'],
        authHeaders($token),
    )->assertCreated()
        ->assertJsonPath('data.invoice_request.status', 'pending');

    $this->getJson("/api/v1/orders/{$order->id}/invoice-request/status", authHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.invoice_status', 'pending')
        ->assertJsonPath('data.invoice_request.id', $created->json('data.invoice_request.id'))
        ->assertJsonPath('data.invoice', null);
});

test('p0 retry read-back create without idempotency key is discoverable in address list', function () {
    [, $token] = akubicaCustomerToken();

    $created = $this->postJson(
        '/api/v1/user/addresses',
        validAddressPayload([
            'street' => 'Calle Read Back',
            'additional_references' => 'Referencia dirigida TI-06',
        ]),
        authHeaders($token),
    )->assertCreated()
        ->json('data.address');

    expect($created['id'])->not->toBeEmpty();

    $addresses = $this->getJson('/api/v1/user/addresses', authHeaders($token))
        ->assertOk()
        ->json('data.addresses');

    expect(collect($addresses)->pluck('id')->all())->toContain($created['id']);
});
