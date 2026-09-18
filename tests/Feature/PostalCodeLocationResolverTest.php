<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryStore;
use App\Models\PostalCodeLocation;
use App\Services\Laboratory\PostalCodeLocationResolver;

it('resolves an existing postal code location', function (): void {
    PostalCodeLocation::query()->create([
        'postal_code' => '06400',
        'latitude' => '19.432100',
        'longitude' => '-99.133200',
        'source' => 'official',
        'confidence' => '0.9000',
    ]);

    $result = app(PostalCodeLocationResolver::class)->resolve('06400', LaboratoryBrand::OLAB);

    expect($result['status'])->toBe('resolved')
        ->and($result['postal_code'])->toBe('06400')
        ->and($result['location']->latitude)->toBe(19.4321)
        ->and($result['location']->longitude)->toBe(-99.1332)
        ->and($result['source'])->toBe('postal_code_locations:official')
        ->and($result['confidence'])->toBe('0.9000');
});

it('returns unresolved for missing postal codes and does not use store centroids', function (): void {
    LaboratoryStore::query()->create([
        'name' => 'Store Same CP',
        'brand' => LaboratoryBrand::OLAB->value,
        'state' => 'Nuevo Leon',
        'address' => 'Address',
        'weekly_hours' => '07:00-15:00',
        'saturday_hours' => '07:00-15:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.test',
        'is_active' => true,
        'postal_code' => '64000',
        'latitude' => '25.670000',
        'longitude' => '-100.310000',
    ]);

    $result = app(PostalCodeLocationResolver::class)->resolve('64000', LaboratoryBrand::OLAB);

    expect($result['status'])->toBe('unresolved')
        ->and($result['location'])->toBeNull()
        ->and($result['source'])->toBeNull();
});

it('resolves independently of laboratory store brands', function (): void {
    PostalCodeLocation::query()->create([
        'postal_code' => '64000',
        'latitude' => '25.670000',
        'longitude' => '-100.310000',
        'source' => 'official',
    ]);

    $olab = app(PostalCodeLocationResolver::class)->resolve('64000', LaboratoryBrand::OLAB);
    $swisslab = app(PostalCodeLocationResolver::class)->resolve('64000', LaboratoryBrand::SWISSLAB);

    expect($olab['status'])->toBe('resolved')
        ->and($swisslab['status'])->toBe('resolved')
        ->and($olab['location']->latitude)->toBe($swisslab['location']->latitude);
});
