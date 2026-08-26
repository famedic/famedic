<?php

use App\Support\EfevooPayGatewayMode;

it('defaults to mock outside production', function () {
    config(['efevoopay.gateway' => null, 'efevoopay.force_simulation' => false]);

    expect(EfevooPayGatewayMode::current())->toBe(EfevooPayGatewayMode::MOCK)
        ->and(EfevooPayGatewayMode::usesMock())->toBeTrue();
});

it('honors explicit mock test and live selectors', function () {
    config(['efevoopay.force_simulation' => false]);

    config(['efevoopay.gateway' => 'test']);
    expect(EfevooPayGatewayMode::current())->toBe(EfevooPayGatewayMode::TEST)
        ->and(EfevooPayGatewayMode::usesHttpGateway())->toBeTrue();

    config(['efevoopay.gateway' => 'live']);
    expect(EfevooPayGatewayMode::current())->toBe(EfevooPayGatewayMode::LIVE);

    config(['efevoopay.gateway' => 'mock']);
    expect(EfevooPayGatewayMode::current())->toBe(EfevooPayGatewayMode::MOCK);
});

it('force simulation overrides an http gateway selector', function () {
    config([
        'efevoopay.gateway' => 'test',
        'efevoopay.force_simulation' => true,
    ]);

    expect(EfevooPayGatewayMode::current())->toBe(EfevooPayGatewayMode::MOCK)
        ->and(EfevooPayGatewayMode::usesMock())->toBeTrue();
});
