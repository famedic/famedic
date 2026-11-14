<?php

use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestPackage;
use App\Models\LaboratoryTestPackageDescriptor;

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

test('B02 exposes mixed studies and commercial descriptors without making descriptors purchasable', function () {
    $study = createOlabTest(['name' => 'Glucosa', 'gda_id' => 'B02-STUDY']);
    $packageTest = createOlabTest([
        'name' => 'Paquete diabético',
        'gda_id' => 'B02-PACKAGE-MIXED',
        'public_price_cents' => 155400,
        'famedic_price_cents' => 67900,
    ]);

    $package = LaboratoryTestPackage::query()->create(['package_test_id' => $packageTest->id]);
    $package->components()->attach($study->id, ['sort_order' => 4]);
    LaboratoryTestPackageDescriptor::query()->create([
        'package_id' => $package->id,
        'description' => 'Biometría hemática completa',
        'sort_order' => 1,
        'validation_status' => LaboratoryTestPackageDescriptor::STATUS_PENDING,
        'validation_reference' => 'RC2-B02-4965',
    ]);
    LaboratoryTestPackageDescriptor::query()->create([
        'package_id' => $package->id,
        'description' => 'Hemoglobina glicolisada (glicohemoglobina)',
        'sort_order' => 2,
        'validation_status' => LaboratoryTestPackageDescriptor::STATUS_VALIDATED,
        'validation_reference' => 'RC2-B02-4965',
    ]);

    $response = $this->getJson("/api/v1/catalog/packages/{$packageTest->id}")
        ->assertOk()
        ->assertJsonPath('data.composition_status', 'incomplete')
        ->assertJsonPath('data.composition.0.type', 'commercial_descriptor')
        ->assertJsonPath('data.composition.0.id', null)
        ->assertJsonPath('data.composition.0.validation_status', 'pending_commercial_validation')
        ->assertJsonPath('data.composition.2.type', 'study')
        ->assertJsonPath('data.composition.2.id', $study->id)
        ->assertJsonPath('data.pricing.famedic_price_cents', 67900);

    expect($response->json('data.composition'))->toHaveCount(3)
        ->and($response->json('data.composition.0.sort_order'))->toBe(1)
        ->and($response->json('data.composition.2.sort_order'))->toBe(4);

    $this->assertDatabaseMissing('laboratory_tests', [
        'name' => 'Biometría hemática completa',
    ]);
});

test('B02 rejects descriptor statuses outside the contract', function () {
    $packageTest = createOlabTest(['gda_id' => 'B02-PACKAGE-STATUS']);
    $package = LaboratoryTestPackage::query()->create(['package_test_id' => $packageTest->id]);

    expect(fn () => LaboratoryTestPackageDescriptor::query()->create([
        'package_id' => $package->id,
        'description' => 'Estado inválido',
        'sort_order' => 1,
        'validation_status' => 'unknown',
    ]))->toThrow(InvalidArgumentException::class);
});
