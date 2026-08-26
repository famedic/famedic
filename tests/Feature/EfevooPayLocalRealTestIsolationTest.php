<?php

use App\Models\User;
use App\Support\LocalExternalIntegrationGate;
use App\Support\EfevooPayLocalRealTestMode;

beforeEach(function () {
    config([
        'efevoopay.gateway' => 'live',
        'efevoopay.local_real_tests.enabled' => true,
        'efevoopay.local_real_tests.allowed_user_email' => 'local-tester@example.test',
    ]);
});

it('blocks external integrations when local real tests use http gateway', function () {
    expect(EfevooPayLocalRealTestMode::blocksExternalIntegrations())->toBeTrue()
        ->and(LocalExternalIntegrationGate::allows('gda'))->toBeFalse()
        ->and(LocalExternalIntegrationGate::allows('activecampaign'))->toBeFalse();
});

it('allows only configured local real test user', function () {
    $allowed = User::factory()->create(['email' => 'local-tester@example.test']);
    $other = User::factory()->create(['email' => 'other@example.test']);

    expect(EfevooPayLocalRealTestMode::userIsAllowed($allowed))->toBeTrue()
        ->and(EfevooPayLocalRealTestMode::userIsAllowed($other))->toBeFalse();
});

it('does not block integrations when gateway is mock', function () {
    config(['efevoopay.gateway' => 'mock']);

    expect(EfevooPayLocalRealTestMode::blocksExternalIntegrations())->toBeFalse()
        ->and(LocalExternalIntegrationGate::allows('murguia'))->toBeTrue();
});
