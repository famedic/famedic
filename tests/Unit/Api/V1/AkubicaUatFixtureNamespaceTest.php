<?php

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Services\Api\V1\Uat\AkubicaUatFixturePlanner;

test('dedicated namespace is explicitly allowlisted and isolated from v1 storage', function () {
    expect(AkubicaUatFixtureContract::isAllowedNamespace(AkubicaUatFixtureContract::NAMESPACE))->toBeTrue()
        ->and(AkubicaUatFixtureContract::isAllowedNamespace(AkubicaUatFixtureContract::DEDICATED_NAMESPACE))->toBeTrue()
        ->and(AkubicaUatFixtureContract::isAllowedNamespace('akubica-uat-v2'))->toBeFalse()
        ->and(AkubicaUatFixtureContract::storagePath('results/result-ready.pdf'))->toBe('akubica-uat-v1/results/result-ready.pdf')
        ->and(AkubicaUatFixtureContract::storagePath('results/result-ready.pdf', AkubicaUatFixtureContract::DEDICATED_NAMESPACE))
        ->toBe('akubica-uat-batch2-a4-v1/results/result-ready.pdf');
});

test('dedicated planner keeps natural keys and storage paths separate', function () {
    $planner = new AkubicaUatFixturePlanner;
    $legacy = $planner->buildPlan('dry-run', false, AkubicaUatFixtureContract::NAMESPACE);
    $dedicated = $planner->buildPlan('dry-run', false, AkubicaUatFixtureContract::DEDICATED_NAMESPACE);

    expect($legacy->namespace)->toBe(AkubicaUatFixtureContract::NAMESPACE)
        ->and($dedicated->namespace)->toBe(AkubicaUatFixtureContract::DEDICATED_NAMESPACE)
        ->and($legacy->storagePaths)->not->toEqual($dedicated->storagePaths)
        ->and($legacy->storagePaths)->each->toStartWith('akubica-uat-v1/')
        ->and($dedicated->storagePaths)->each->toStartWith('akubica-uat-batch2-a4-v1/');
});
