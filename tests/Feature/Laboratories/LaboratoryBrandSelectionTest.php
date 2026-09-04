<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryStore;
use Inertia\Testing\AssertableInertia as Assert;

it('renders laboratory brand selection with active store counts and states', function () {
    brandSelectionStore('Swisslab Monterrey', LaboratoryBrand::SWISSLAB, 'Nuevo Leon');
    brandSelectionStore('Olab Narvarte', LaboratoryBrand::OLAB, 'Ciudad de Mexico');
    brandSelectionStore('Olab Metepec', LaboratoryBrand::OLAB, 'Estado de Mexico');
    brandSelectionStore('Olab Inactiva', LaboratoryBrand::OLAB, 'Puebla', active: false);
    brandSelectionStore('Azteca Centro', LaboratoryBrand::AZTECA, 'Ciudad de Mexico');
    $deleted = brandSelectionStore('Jenner Borrada', LaboratoryBrand::JENNER, 'Ciudad de Mexico');
    $deleted->delete();

    $this->get(route('laboratory-brand-selection', ['state' => 'Ciudad de Mexico']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LaboratoryBrandSelection')
            ->has('brands', 5)
            ->where('brands.0.brand', 'swisslab')
            ->where('brands.0.active_store_count', 1)
            ->where('brands.0.states.0', 'Nuevo Leon')
            ->where('brands.1.brand', 'olab')
            ->where('brands.1.active_store_count', 2)
            ->where('brands.1.states.0', 'Ciudad de Mexico')
            ->where('brands.1.states.1', 'Estado de Mexico')
            ->where('brands.2.brand', 'azteca')
            ->where('brands.2.active_store_count', 1)
            ->where('brands.3.brand', 'jenner')
            ->where('brands.3.active_store_count', 0)
            ->where('brands.4.brand', 'liacsa')
            ->where('brands.4.active_store_count', 0)
            ->where('states.0', 'Ciudad de Mexico')
            ->where('states.1', 'Estado de Mexico')
            ->where('states.2', 'Nuevo Leon')
            ->missing('states.3'));
});

function brandSelectionStore(
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
