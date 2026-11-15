<?php

use App\Models\LaboratoryTestPackage;
use App\Models\LaboratoryTestPackageDescriptor;

/*
 * RC2 B02 — estado comercial honesto de los estudios vinculados.
 * La vinculación catálogo→paquete NO implica aprobación comercial.
 * Ninguna prueba modifica precios ni feature_list.
 */

function b02PilotLikePackage(): array
{
    $study = createOlabTest(['name' => 'Glucosa en sangre', 'gda_id' => 'B02CS-STUDY-'.bin2hex(random_bytes(3))]);
    $packageTest = createOlabTest([
        'name' => 'Paquete perfil sintético',
        'gda_id' => 'B02CS-PACKAGE-'.bin2hex(random_bytes(3)),
        'public_price_cents' => 155400,
        'famedic_price_cents' => 67900,
        'feature_list' => ['valor-legado-no-usado'],
    ]);
    $package = LaboratoryTestPackage::query()->create(['package_test_id' => $packageTest->id]);

    return [$package, $packageTest, $study];
}

test('B02 linked study without commercial approval is reported as pending, not validated', function () {
    [$package, $packageTest, $study] = b02PilotLikePackage();
    // attach sin validation_status: la columna usa el default pending_commercial_validation
    $package->components()->attach($study->id, ['sort_order' => 1]);

    $this->getJson("/api/v1/catalog/packages/{$packageTest->id}")
        ->assertOk()
        ->assertJsonPath('data.composition.0.type', 'study')
        ->assertJsonPath('data.composition.0.id', $study->id)
        ->assertJsonPath('data.composition.0.validation_status', LaboratoryTestPackageDescriptor::STATUS_PENDING)
        ->assertJsonPath('data.composition.0.validation_reference', null)
        ->assertJsonPath('data.composition_status', 'incomplete')
        ->assertJsonPath('data.pricing.famedic_price_cents', 67900)
        ->assertJsonPath('data.pricing.public_price_cents', 155400)
        ->assertJsonPath('data.pricing.savings_cents', 87500);
});

test('B02 composition is validated only when every study and descriptor is commercially approved', function () {
    [$package, $packageTest, $study] = b02PilotLikePackage();
    $package->components()->attach($study->id, [
        'sort_order' => 2,
        'validation_status' => LaboratoryTestPackageDescriptor::STATUS_VALIDATED,
        'validation_reference' => 'RC2-B02-SINTETICO-APROBADO',
    ]);
    LaboratoryTestPackageDescriptor::query()->create([
        'package_id' => $package->id,
        'description' => 'Descriptor sintético pendiente',
        'sort_order' => 1,
        'validation_status' => LaboratoryTestPackageDescriptor::STATUS_PENDING,
    ]);

    $this->getJson("/api/v1/catalog/packages/{$packageTest->id}")
        ->assertOk()
        ->assertJsonPath('data.composition.1.validation_status', LaboratoryTestPackageDescriptor::STATUS_VALIDATED)
        ->assertJsonPath('data.composition.1.validation_reference', 'RC2-B02-SINTETICO-APROBADO')
        ->assertJsonPath('data.composition_status', 'incomplete');

    LaboratoryTestPackageDescriptor::query()->where('package_id', $package->id)
        ->update(['validation_status' => LaboratoryTestPackageDescriptor::STATUS_VALIDATED]);

    $this->getJson("/api/v1/catalog/packages/{$packageTest->id}")
        ->assertOk()
        ->assertJsonPath('data.composition_status', 'validated');
});

test('B02 a validated descriptor does not mask a pending linked study', function () {
    [$package, $packageTest, $study] = b02PilotLikePackage();
    $package->components()->attach($study->id, ['sort_order' => 2]);
    LaboratoryTestPackageDescriptor::query()->create([
        'package_id' => $package->id,
        'description' => 'Descriptor sintético aprobado',
        'sort_order' => 1,
        'validation_status' => LaboratoryTestPackageDescriptor::STATUS_VALIDATED,
    ]);

    $this->getJson('/api/v1/catalog/packages?brand=olab')
        ->assertOk()
        ->assertJsonPath('data.packages.0.composition.1.validation_status', LaboratoryTestPackageDescriptor::STATUS_PENDING)
        ->assertJsonPath('data.packages.0.composition_status', 'incomplete');
});

test('B02 patch does not alter package prices or feature_list', function () {
    [$package, $packageTest, $study] = b02PilotLikePackage();
    $package->components()->attach($study->id, ['sort_order' => 1]);

    $this->getJson("/api/v1/catalog/packages/{$packageTest->id}")->assertOk();

    $packageTest->refresh();
    expect((int) $packageTest->famedic_price_cents)->toBe(67900)
        ->and((int) $packageTest->public_price_cents)->toBe(155400)
        ->and($packageTest->feature_list)->toBe(['valor-legado-no-usado']);
});
