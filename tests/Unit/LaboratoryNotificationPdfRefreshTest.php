<?php

use App\Models\LaboratoryNotification;

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
    $purchase = \App\Models\LaboratoryPurchase::factory()->create([
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
