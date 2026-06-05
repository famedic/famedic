<?php

use App\Actions\Laboratories\ResolveGdaResultsPdfAction;
use App\Models\LaboratoryNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeResultsNotification(array $overrides = []): LaboratoryNotification
{
    return LaboratoryNotification::query()->create(array_merge([
        'notification_type' => LaboratoryNotification::TYPE_RESULTS,
        'lineanegocio' => LaboratoryNotification::LINEA_NEGOCIO_RESULTS,
        'gda_order_id' => 'GDA-ORDER-1',
        'gda_consecutivo' => 'GDA-ORDER-1',
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

it('uses cached pdf when there are no newer results notifications', function () {
    $notification = makeResultsNotification([
        'results_pdf_base64' => base64_encode('fresh-pdf'),
        'gda_message' => [
            'results_source' => 'gda_api',
            'results_fetched_at' => now()->subHour()->toISOString(),
        ],
    ]);

    expect($notification->shouldRefreshPdfFromGda())->toBeFalse();
});

it('refreshes pdf that came from webhook payload instead of consult api', function () {
    $notification = makeResultsNotification([
        'results_pdf_base64' => base64_encode('webhook-pdf'),
        'gda_message' => null,
    ]);

    expect($notification->shouldRefreshPdfFromGda())->toBeTrue();
});

it('fetches from gda api when cache is stale and stores fetch metadata', function () {
    $notification = makeResultsNotification([
        'results_pdf_base64' => base64_encode('stale-pdf'),
        'gda_message' => [
            'results_source' => 'gda_api',
            'results_fetched_at' => now()->subDays(3)->toISOString(),
        ],
    ]);

    makeResultsNotification([
        'results_received_at' => now(),
        'results_pdf_base64' => null,
    ]);

    $this->mock(\App\Actions\Laboratories\GetGDAResultsAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->andReturn(['infogda_resultado_b64' => base64_encode('updated-pdf')]);
    });

    $result = app(ResolveGdaResultsPdfAction::class)($notification);

    expect($result['refreshed'])->toBeTrue()
        ->and($result['pdf_base64'])->toBe(base64_encode('updated-pdf'))
        ->and(data_get($result['notification']->gda_message, 'results_source'))->toBe('gda_api')
        ->and(data_get($result['notification']->gda_message, 'results_fetched_at'))->not->toBeNull();
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

    expect(LaboratoryNotification::hasUpdatedResultsSinceLastPatientAccess(100, 'GDA-ORDER-1', 'GDA-ORDER-1'))
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

    expect(LaboratoryNotification::hasUpdatedResultsSinceLastPatientAccess(100, 'GDA-ORDER-1', 'GDA-ORDER-1'))
        ->toBeFalse();
});
