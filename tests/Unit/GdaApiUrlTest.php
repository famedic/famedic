<?php

use App\Support\GDA\GdaApiUrl;

test('gda api url builds production style endpoint', function () {
    config([
        'services.gda.url' => 'https://integracionv3.gda.mx',
        'services.gda.api_path' => 'infogda-fullV3',
    ]);

    expect(GdaApiUrl::endpoint('service-request'))
        ->toBe('https://integracionv3.gda.mx/infogda-fullV3/service-request');
});

test('gda api url builds qa intfhir endpoint', function () {
    config([
        'services.gda.url' => 'https://intfhirapiq.gda.mx',
        'services.gda.api_path' => 'intfhir-v3b',
    ]);

    expect(GdaApiUrl::endpoint('patient'))
        ->toBe('https://intfhirapiq.gda.mx/intfhir-v3b/patient');
});

test('gda force real api disables simulation in local', function () {
    config([
        'app.env' => 'local',
        'services.gda.force_real_api' => true,
    ]);

    expect(GdaApiUrl::shouldSimulateOrders())->toBeFalse();
});

test('gda simulates orders in local by default', function () {
    config([
        'app.env' => 'local',
        'services.gda.force_real_api' => false,
    ]);

    expect(GdaApiUrl::shouldSimulateOrders())->toBeTrue();
});
