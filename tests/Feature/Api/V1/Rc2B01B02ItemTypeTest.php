<?php

// RC2 B01/B02 (D8): additive catalog item_type (study|package). The package composition stays uncertified until commercial approval.
use App\Models\LaboratoryTestPackage;

test('B01 a plain study is item_type=study in list and detail', function () {
    $study = createOlabTest(['name' => 'RC2 B01 estudio', 'gda_id' => 'RC2-B01-STUDY', 'public_price_cents' => 45000, 'famedic_price_cents' => 35000]);

    $row = collect($this->getJson('/api/v1/catalog/laboratory-tests?brand=olab&search=RC2-B01-STUDY')->assertOk()->json('data.laboratory_tests'))
        ->firstWhere('id', $study->id);
    expect($row['item_type'])->toBe('study')
        ->and($row['pricing']['comparison_status'])->toBe('available');

    $this->getJson("/api/v1/catalog/laboratory-tests/{$study->id}")->assertOk()
        ->assertJsonPath('data.item_type', 'study')
        ->assertJsonPath('data.pricing.savings_cents', 10000);
});

test('B02 an explicitly classified package is item_type=package in list, detail and packages routes; composition stays incomplete', function () {
    $component = createOlabTest(['name' => 'RC2 componente', 'gda_id' => 'RC2-B02-COMP']);
    $packageTest = createOlabTest(['name' => 'RC2 paquete', 'gda_id' => 'RC2-B02-PKG', 'public_price_cents' => 155400, 'famedic_price_cents' => 67900]);
    $package = LaboratoryTestPackage::query()->create(['package_test_id' => $packageTest->id]);
    $package->components()->attach([$component->id => ['sort_order' => 1]]);

    $row = collect($this->getJson('/api/v1/catalog/laboratory-tests?brand=olab&search=RC2-B02')->assertOk()->json('data.laboratory_tests'))
        ->keyBy('id');
    expect($row[$packageTest->id]['item_type'])->toBe('package')
        ->and($row[$component->id]['item_type'])->toBe('study');

    $this->getJson("/api/v1/catalog/laboratory-tests/{$packageTest->id}")->assertOk()
        ->assertJsonPath('data.item_type', 'package');

    $this->getJson('/api/v1/catalog/packages?brand=olab')->assertOk()
        ->assertJsonPath('data.packages.0.item_type', 'package');

    $this->getJson("/api/v1/catalog/packages/{$packageTest->id}")->assertOk()
        ->assertJsonPath('data.item_type', 'package')
        ->assertJsonPath('data.brand', 'olab')
        ->assertJsonPath('data.pricing.savings_cents', 87500)
        ->assertJsonPath('data.composition_status', 'incomplete');
});
