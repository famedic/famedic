<?php

use App\Services\ActiveCampaign\ActiveCampaignLocationMapper;

it('maps complete ActiveCampaign geodata to a safe approximate location', function () {
    $location = app(ActiveCampaignLocationMapper::class)->fromContactData([
        'geoCity' => 'Monterrey',
        'geoState' => 'Nuevo Leon',
        'geo_country' => 'Mexico',
        'geoCountry2' => 'MX',
        'geoZip' => '64000',
        'geoLat' => '25.686600',
        'geoLon' => '-100.316100',
        'geoIp4' => '187.190.1.1',
        'geoTz' => 'America/Monterrey',
    ]);

    expect($location)->toBe([
        'city' => 'Monterrey',
        'state' => 'Nuevo Leon',
        'country' => 'Mexico',
        'timezone' => 'America/Monterrey',
        'source' => 'activecampaign',
    ])
        ->and($location)->not->toHaveKey('geoIp4')
        ->and($location)->not->toHaveKey('ip')
        ->and($location)->not->toHaveKey('geoLat')
        ->and($location)->not->toHaveKey('geoLon')
        ->and($location)->not->toHaveKey('lat')
        ->and($location)->not->toHaveKey('lon')
        ->and($location)->not->toHaveKey('zip');
});

it('maps partial ActiveCampaign geodata without inventing values', function () {
    $location = app(ActiveCampaignLocationMapper::class)->fromContactData([
        'geoCity' => 'Guadalajara',
        'geoCountry2' => 'MX',
    ]);

    expect($location)->toBe([
        'city' => 'Guadalajara',
        'state' => null,
        'country' => 'Mexico',
        'timezone' => null,
        'source' => 'activecampaign',
    ]);
});

it('normalizes empty geodata to null', function () {
    $location = app(ActiveCampaignLocationMapper::class)->fromContactData([
        'geoCity' => '',
        'geoState' => ' ',
        'geo_country' => '0',
        'geoTz' => '',
    ]);

    expect($location)->toBeNull();
});

it('returns null when contact data has no geo fields', function () {
    expect(app(ActiveCampaignLocationMapper::class)->fromContactData(['email' => 'a@example.com']))->toBeNull();
});

it('does not persist ip or coordinate fields even when ActiveCampaign sends them alone', function () {
    $location = app(ActiveCampaignLocationMapper::class)->fromContactData([
        'geoIp4' => '187.190.1.1',
        'geoLat' => '25.686600',
        'geoLon' => '-100.316100',
        'geoZip' => '64000',
    ]);

    expect($location)->toBeNull();
});
