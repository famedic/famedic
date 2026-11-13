<?php

use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestPackage;

test('B02 lists only explicitly classified packages with composition and pricing', function () {
    $componentA = createOlabTest([
        'name' => 'Componente A',
        'gda_id' => 'B02-COMP-A',
        'feature_list' => ['legacy-inference-only'],
    ]);
    $componentB = createOlabTest([
        'name' => 'Componente B',
        'gda_id' => 'B02-COMP-B',
    ]);
    $packageTest = createOlabTest([
        'name' => 'Paquete explícito',
        'gda_id' => 'B02-PACKAGE',
        'public_price_cents' => 10000,
        'famedic_price_cents' => 8000,
        'feature_list' => ['not-used-for-classification'],
    ]);
    $unclassified = createOlabTest([
        'name' => 'No es paquete',
        'gda_id' => 'B02-NOT-PACKAGE',
        'feature_list' => ['component-looking-value'],
    ]);

    $package = LaboratoryTestPackage::query()->create(['package_test_id' => $packageTest->id]);
    $package->components()->attach([
        $componentA->id => ['sort_order' => 1],
        $componentB->id => ['sort_order' => 2],
    ]);

    $this->getJson('/api/v1/catalog/packages?brand=olab')
        ->assertOk()
        ->assertJsonPath('data.packages.0.id', $packageTest->id)
        ->assertJsonPath('data.packages.0.package_classification', 'explicit')
        ->assertJsonPath('data.packages.0.composition.0.id', $componentA->id)
        ->assertJsonPath('data.packages.0.composition.1.id', $componentB->id)
        ->assertJsonPath('data.packages.0.pricing.savings_cents', 2000)
        ->assertJsonMissing(['id' => $unclassified->id]);

    $this->getJson("/api/v1/catalog/packages/{$packageTest->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $packageTest->id)
        ->assertJsonCount(2, 'data.composition');
});

test('B02 does not infer a package from feature_list', function () {
    $test = createOlabTest([
        'name' => 'Solo con feature list',
        'gda_id' => 'B02-FEATURE-ONLY',
        'feature_list' => ['one', 'two'],
    ]);

    $this->getJson('/api/v1/catalog/packages')
        ->assertOk()
        ->assertJsonCount(0, 'data.packages');

    $this->getJson("/api/v1/catalog/packages/{$test->id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'PACKAGE_NOT_FOUND');
});
