<?php

use App\Models\LaboratoryNotification;

// INT-031 (RC05): this Unit file reads and writes the schema; migrate it and roll back per test instead of
// depending on a Feature test having migrated the database first.
uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

// INT-031 (fase 2, RC18): laboratory_notifications.laboratory_purchase_id has a FK to laboratory_purchases; the
// helper's literal purchase id 100 was an orphan, and LaboratoryPurchaseFactory has an empty definition (the table
// requires gda_order_id, name, ...). Create the referenced purchase with explicit synthetic data. Assertions unchanged.
function pdfRefreshLaboratoryPurchase(array $attributes = []): \App\Models\LaboratoryPurchase
{
    $user = \App\Models\User::factory()->withRegularCustomer()->create();

    return \App\Models\LaboratoryPurchase::query()->forceCreate(array_merge([
        'customer_id' => $user->customer->id,
        'brand' => \App\Enums\LaboratoryBrand::OLAB,
        'gda_order_id' => 'GDA-ORDER-1',
        'gda_consecutivo' => 1,
        'name' => 'Paciente',
        'paternal_lastname' => 'Sintetico',
        'maternal_lastname' => 'Prueba',
        'phone' => '5500000000',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => \App\Enums\Gender::MALE,
        'street' => 'Calle Test',
        'number' => '100',
        'neighborhood' => 'Centro',
        'state' => 'CDMX',
        'city' => 'CDMX',
        'zipcode' => '01000',
        'total_cents' => 35000,
    ], $attributes));
}

beforeEach(function () {
    pdfRefreshLaboratoryPurchase(['id' => 100]);
});


function makeResultsNotification(array $overrides = []): LaboratoryNotification
{
    return LaboratoryNotification::query()->create(array_merge([
        'notification_type' => LaboratoryNotification::TYPE_RESULTS,
        'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
        'gda_order_id' => 'GDA-ORDER-1',
        'gda_consecutivo' => 1,
        'laboratory_purchase_id' => 100,
        'status' => LaboratoryNotification::STATUS_PROCESSED,
        'gda_status' => LaboratoryNotification::GDA_STATUS_COMPLETED,
        'results_received_at' => now()->subDays(3),
        'payload' => [
            'header' => ['marca' => 5],
            'requisition' => ['convenio' => 99999, 'value' => 'REQ-1'],
            'id' => 'GDA-ORDER-1',
        ],
    ], $overrides));
}

it('refreshes cached pdf when a newer results notification exists for the same order', function () {
    $older = makeResultsNotification([
        'results_pdf_base64' => base64_encode('old-pdf'),
        'gda_message' => [
            'results_source' => 'gda_api',
            'results_fetched_at' => now()->subDays(3)->toISOString(),
        ],
        'updated_at' => now()->subDays(3),
    ]);

    makeResultsNotification([
        'results_received_at' => now()->subDay(),
        'results_pdf_base64' => null,
        'created_at' => now()->subDay(),
    ]);

    expect($older->fresh()->shouldRefreshPdfFromGda())->toBeTrue();
});

it('does not refresh when results are stored on purchase', function () {
    // INT-031 (fase 2, RC18): explicit synthetic purchase instead of the empty LaboratoryPurchaseFactory.
    $purchase = pdfRefreshLaboratoryPurchase([
        'gda_order_id' => 'GDA-ORDER-2',
        'gda_consecutivo' => 2,
        'results' => 'results/gda-1-abc.pdf',
    ]);

    \Illuminate\Support\Facades\Storage::fake();
    \Illuminate\Support\Facades\Storage::put('results/gda-1-abc.pdf', '%PDF-1.4');

    $notification = makeResultsNotification([
        'laboratory_purchase_id' => $purchase->id,
        'gda_message' => [
            'results_source' => 'storage',
            'results_fetched_at' => now()->subHour()->toISOString(),
        ],
    ]);

    expect($notification->fresh()->shouldRefreshPdfFromGda())->toBeFalse();
})->skip(fn () => ! \Illuminate\Support\Facades\Schema::hasTable('laboratory_purchases'), 'Requires laboratory_purchases table');

it('refreshes pdf that came from webhook payload instead of consult api', function () {
    $notification = makeResultsNotification([
        'results_pdf_base64' => base64_encode('webhook-pdf'),
        'gda_message' => null,
    ]);

    expect($notification->shouldRefreshPdfFromGda())->toBeTrue();
});

it('shows updated results badge when a newer notification arrives after patient access', function () {
    makeResultsNotification([
        'results_pdf_base64' => base64_encode('old-pdf'),
        'read_at' => now()->subDays(3),
        'gda_message' => [
            'results_source' => 'gda_api',
            'results_fetched_at' => now()->subDays(3)->toISOString(),
        ],
        'results_received_at' => now()->subDays(3),
    ]);

    makeResultsNotification([
        'results_received_at' => now()->subDay(),
        'results_pdf_base64' => null,
    ]);

    expect(LaboratoryNotification::hasUpdatedResultsSinceLastPatientAccess(100, 'GDA-ORDER-1', 1))
        ->toBeTrue();
});

it('hides updated results badge after patient accessed the latest notification', function () {
    makeResultsNotification([
        'results_pdf_base64' => base64_encode('old-pdf'),
        'read_at' => now()->subDays(3),
        'results_received_at' => now()->subDays(3),
    ]);

    makeResultsNotification([
        'results_received_at' => now()->subDay(),
        'read_at' => now()->subHour(),
        'results_pdf_base64' => base64_encode('new-pdf'),
        'gda_message' => [
            'results_source' => 'gda_api',
            'results_fetched_at' => now()->subHour()->toISOString(),
        ],
    ]);

    expect(LaboratoryNotification::hasUpdatedResultsSinceLastPatientAccess(100, 'GDA-ORDER-1', 1))
        ->toBeFalse();
});
