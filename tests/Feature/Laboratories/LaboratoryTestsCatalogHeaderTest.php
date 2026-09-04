<?php

use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Laboratories\LaboratoryTestsController;
use App\Models\LaboratoryStore;

it('exposes active store summary and directory links for the selected laboratory brand', function () {
    laboratoryTestsCatalogStore('Olab Narvarte', LaboratoryBrand::OLAB, 'CIUDAD DE MEXICO');
    laboratoryTestsCatalogStore('Olab Metepec', LaboratoryBrand::OLAB, 'Estado de México');
    laboratoryTestsCatalogStore('Olab Inactiva', LaboratoryBrand::OLAB, 'Nuevo Leon', active: false);
    laboratoryTestsCatalogStore('Swisslab Monterrey', LaboratoryBrand::SWISSLAB, 'Nuevo Leon');

    $brandData = laboratoryTestsCatalogBrandData(LaboratoryBrand::OLAB);

    expect($brandData['value'])->toBe(LaboratoryBrand::OLAB->value)
        ->and($brandData['active_store_count'])->toBe(2)
        ->and($brandData['states'])->toBe(['CIUDAD DE MEXICO', 'Estado de México'])
        ->and($brandData['store_directory_url'])->toBe(route('laboratory-stores.index', [
            'brand' => LaboratoryBrand::OLAB->value,
        ]))
        ->and($brandData['nearby_store_directory_url'])->toBe(route('laboratory-stores.index', [
            'brand' => LaboratoryBrand::OLAB->value,
        ]));
});

it('preserves each laboratory brand in store directory links', function () {
    foreach (LaboratoryBrand::cases() as $brand) {
        laboratoryTestsCatalogStore("{$brand->label()} Centro", $brand, 'Ciudad de Mexico');

        $brandData = laboratoryTestsCatalogBrandData($brand);

        expect($brandData['value'])->toBe($brand->value)
            ->and($brandData['active_store_count'])->toBe(1)
            ->and($brandData['store_directory_url'])->toBe(route('laboratory-stores.index', [
                'brand' => $brand->value,
            ]))
            ->and($brandData['nearby_store_directory_url'])->toBe(route('laboratory-stores.index', [
                'brand' => $brand->value,
            ]));

        LaboratoryStore::query()->delete();
    }
});

function laboratoryTestsCatalogStore(
    string $name,
    LaboratoryBrand $brand,
    string $state,
    bool $active = true,
): LaboratoryStore {
    return LaboratoryStore::query()->create([
        'name' => $name,
        'brand' => $brand,
        'state' => $state,
        'address' => "{$name}, {$state}",
        'weekly_hours' => '07:00-15:00',
        'saturday_hours' => '07:00-15:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example.test',
        'is_active' => $active,
    ]);
}

function laboratoryTestsCatalogBrandData(LaboratoryBrand $brand): array
{
    $controller = new LaboratoryTestsController;
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('brandData');

    return $method->invoke($controller, $brand);
}
