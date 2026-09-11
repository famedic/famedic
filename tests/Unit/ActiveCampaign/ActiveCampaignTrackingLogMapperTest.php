<?php

use App\Services\ActiveCampaign\ActiveCampaignTrackingLogMapper;

function trackingLogActivity(array $overrides = []): array
{
    return array_merge([
        'id' => 'activity-1',
        'tstamp' => '2026-08-21T13:41:00-05:00',
        'reference_type' => 'TrackingLog',
        'reference_id' => 'track-1',
        'reference_action' => '',
        'jsonData' => [
            'url' => 'https://famedic.com.mx/laboratories',
            'title' => 'Laboratorios FAMEDIC',
        ],
    ], $overrides);
}

it('maps a valid TrackingLog with a full URL', function () {
    $mapped = app(ActiveCampaignTrackingLogMapper::class)->fromActivity(trackingLogActivity());

    expect($mapped['path'])->toBe('/laboratories')
        ->and($mapped['title'])->toBe('Laboratorios FAMEDIC')
        ->and($mapped['label'])->toBe('Catalogo de laboratorios')
        ->and($mapped['source'])->toBe('activecampaign_site_tracking')
        ->and($mapped['raw_reference_type'])->toBe('TrackingLog')
        ->and($mapped['raw_reference_id'])->toBe('track-1');
});

it('strips query strings and fragments from safe TrackingLog URLs', function () {
    $mapper = app(ActiveCampaignTrackingLogMapper::class);

    expect($mapper->fromActivity(trackingLogActivity([
        'jsonData' => ['url' => 'https://famedic.com.mx/laboratories?brand=olab'],
    ]))['path'])->toBe('/laboratories');

    expect($mapper->fromActivity(trackingLogActivity([
        'jsonData' => ['url' => 'https://famedic.com.mx/laboratory/olab/checkout#payment'],
    ]))['path'])->toBe('/laboratory/olab/checkout');
});

it('ignores sensitive TrackingLog paths', function (string $url) {
    expect(app(ActiveCampaignTrackingLogMapper::class)->fromActivity(trackingLogActivity([
        'jsonData' => ['url' => $url],
    ])))->toBeNull();
})->with([
    'admin' => ['https://famedic.com.mx/admin/carts'],
    'otp' => ['https://famedic.com.mx/otp/verify/123'],
    'token query' => ['https://famedic.com.mx/laboratory/olab/checkout?token=abc'],
    'reset password' => ['https://famedic.com.mx/reset-password/abc'],
    '3ds' => ['https://famedic.com.mx/payment-methods/3ds/callback'],
    'results' => ['https://famedic.com.mx/laboratory-purchases/42/results'],
]);

it('ignores generic Log activities and missing timestamps', function () {
    $mapper = app(ActiveCampaignTrackingLogMapper::class);

    expect($mapper->fromActivity(trackingLogActivity([
        'reference_type' => 'Log',
    ])))->toBeNull();

    expect($mapper->fromActivity(trackingLogActivity([
        'tstamp' => null,
    ])))->toBeNull();
});
