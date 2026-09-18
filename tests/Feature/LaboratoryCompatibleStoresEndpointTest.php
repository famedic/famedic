<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreHour;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\PostalCodeLocation;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->withoutMiddleware([
        \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
        \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
        \App\Http\Middleware\EnsurePhoneIsVerified::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);

    seedCompatibleStoreCapabilities();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 10:00:00', 'America/Mexico_City'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('requires authentication', function (): void {
    $this->getJson(route('laboratory.cart.compatible-stores'))
        ->assertUnauthorized();
});

it('returns compatible and incompatible stores for the current laboratory cart', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('ANGIOTAC DE ABDOMEN', 'Tomografía'));
    compatibleCartItem($user, compatibleStudy('ECO DE MAMA BILATERAL', 'Ultrasonido'));
    compatibleCartItem($user, compatiblePackage('PERFIL MUJER MENOR 40', [
        'BIOMETRÍA HEMÁTICA COMPLETA',
        'ECO DE MAMA BILATERAL',
        'EXAMEN GENERAL DE ORINA',
        'PAPANICOLAOU (CITOLOGÍA VAGINAL)',
    ]));

    compatibleStore('Swisslab Completa', LaboratoryBrand::SWISSLAB, ['laboratorio', 'tomografia', 'papanicolaou', 'ultrasonido_especial']);
    compatibleStore('Swisslab Parcial', LaboratoryBrand::SWISSLAB, ['laboratorio', 'ultrasonido_especial']);
    compatibleStore('Olab Completa', LaboratoryBrand::OLAB, ['laboratorio', 'tomografia', 'papanicolaou', 'ultrasonido_especial']);

    $response = $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', ['brand' => LaboratoryBrand::SWISSLAB->value]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.resolution_status', 'resolved')
        ->assertJsonPath('data.is_resolvable', true)
        ->assertJsonPath('data.branches.0.name', 'Swisslab Completa')
        ->assertJsonPath('data.branches.0.isCompatible', true)
        ->assertJsonPath('data.branches.0.distanceKm', null)
        ->assertJsonPath('data.branches.1.name', 'Swisslab Parcial')
        ->assertJsonPath('data.branches.1.isCompatible', false)
        ->assertJsonPath('data.branches.1.missingRequirements.0.capability', 'papanicolaou')
        ->assertJsonPath('data.branches.1.missingRequirements.1.capability', 'tomografia')
        ->assertJsonCount(2, 'data.branches');

    expect(collect($response->json('data.branches.0.groups'))->contains(
        fn (array $group) => $group['operator'] === 'any'
            && collect($group['matchedCapabilities'])->pluck('capability')->contains('ultrasonido_especial')
    ))->toBeTrue();
});

it('calculates distance when optional location is provided', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));

    compatibleStore('Cerca', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '19.3902300', longitude: '-99.1740300');
    compatibleStore('Lejos', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '19.3650650', longitude: '-99.1781010');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'latitude' => '19.3902300123',
            'longitude' => '-99.1740300123',
        ]))
        ->assertOk()
        ->assertJsonPath('data.branches.0.name', 'Cerca')
        ->assertJsonPath('data.branches.0.distanceKm', 0)
        ->assertJsonPath('data.branches.1.name', 'Lejos');
});

it('reports unknown carts without presenting stores as fully compatible', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatiblePackage('PAQUETE PARCIAL', [
        'BIOMETRÍA HEMÁTICA COMPLETA',
        'COMPONENTE OPERATIVO NO IDENTIFICABLE',
    ]));
    compatibleStore('Swisslab Lab', LaboratoryBrand::SWISSLAB, ['laboratorio']);

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', ['brand' => LaboratoryBrand::SWISSLAB->value]))
        ->assertOk()
        ->assertJsonPath('data.resolution_status', 'unknown')
        ->assertJsonPath('data.is_resolvable', false)
        ->assertJsonPath('data.branches.0.isCompatible', false)
        ->assertJsonPath('data.branches.0.matchLevel', 'UNKNOWN')
        ->assertJsonPath('data.branches.0.reasons.0', 'requirements_unknown');
});

it('returns a stable empty cart response', function (): void {
    $user = compatibleStoresUser();

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', ['brand' => LaboratoryBrand::SWISSLAB->value]))
        ->assertOk()
        ->assertJsonPath('data.resolution_status', 'empty_cart')
        ->assertJsonPath('data.is_resolvable', false)
        ->assertJsonPath('data.compatible_branches_count', 0)
        ->assertJsonCount(0, 'data.branches');
});

it('returns only the requested brand branches', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo', LaboratoryBrand::OLAB));
    compatibleCartItem($user, compatibleStudy('ANGIOTAC DE ABDOMEN', 'Tomografía', LaboratoryBrand::SWISSLAB));

    compatibleStore('OLAB Lab', LaboratoryBrand::OLAB, ['laboratorio']);
    compatibleStore('Swiss Tomo', LaboratoryBrand::SWISSLAB, ['tomografia']);

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', ['brand' => LaboratoryBrand::OLAB->value]))
        ->assertOk()
        ->assertJsonPath('data.brands.olab.branches.0.name', 'OLAB Lab')
        ->assertJsonMissingPath('data.brands.swisslab')
        ->assertJsonCount(1, 'data.brands')
        ->assertJsonCount(1, 'data.branches');
});

it('validates location parameters', function (): void {
    $user = compatibleStoresUser();

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'latitude' => '91',
            'longitude' => '-99',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('latitude');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'latitude' => '19',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('longitude');
});

it('validates postal code format', function (): void {
    $user = compatibleStoresUser();

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '1234',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('postal_code');
});

it('orders compatible stores by distance from a resolved postal code and persists it', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));

    postalCodeLocation('03100', '19.390230', '-99.174030');
    compatibleStore('CP Base', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '19.3902300', longitude: '-99.1740300', postalCode: '03100');
    compatibleStore('Compatible Cerca', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '19.3912300', longitude: '-99.1740300', postalCode: '03200');
    compatibleStore('Compatible Lejos', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '19.4500000', longitude: '-99.2000000', postalCode: '03300');
    compatibleStore('Incompatible Muy Cerca', LaboratoryBrand::SWISSLAB, ['tomografia'], latitude: '19.3902400', longitude: '-99.1740300', postalCode: '03100');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '03100',
        ]))
        ->assertOk()
        ->assertJsonPath('data.meta.postal_code', '03100')
        ->assertJsonPath('data.meta.postal_code_location_status', 'resolved')
        ->assertJsonPath('data.meta.location.status', 'resolved')
        ->assertJsonPath('data.branches.0.name', 'CP Base')
        ->assertJsonPath('data.branches.0.isCompatible', true)
        ->assertJsonPath('data.branches.1.name', 'Compatible Cerca')
        ->assertJsonPath('data.branches.1.isCompatible', true)
        ->assertJsonPath('data.branches.2.name', 'Compatible Lejos')
        ->assertJsonPath('data.branches.2.isCompatible', true)
        ->assertJsonPath('data.branches.3.name', 'Incompatible Muy Cerca')
        ->assertJsonPath('data.branches.3.isCompatible', false);

    $this->assertDatabaseHas('laboratory_checkout_drafts', [
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB->value,
        'postal_code' => '03100',
    ]);
});

it('reuses a persisted postal code when the request does not include one', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));
    postalCodeLocation('03100', '19.390230', '-99.174030');
    compatibleStore('CP Base', LaboratoryBrand::SWISSLAB, ['laboratorio'], postalCode: '03100');

    \App\Models\LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        'postal_code' => '03100',
    ]);

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
        ]))
        ->assertOk()
        ->assertJsonPath('data.meta.postal_code', '03100')
        ->assertJsonPath('data.meta.postal_code_location_status', 'resolved');
});

it('clears a persisted postal code when explicitly requested', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));
    postalCodeLocation('64000', '25.671400', '-100.309000');
    $store = compatibleStore('Swisslab Lab', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '25.7000000', longitude: '-100.3090000');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '64000',
        ]))
        ->assertOk()
        ->assertJsonPath('data.meta.postal_code', '64000')
        ->assertJsonPath('data.branches.0.distanceKm', fn ($value) => $value !== null);

    $this->assertDatabaseHas('laboratory_checkout_drafts', [
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB->value,
        'postal_code' => '64000',
    ]);

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'clear_postal_code' => true,
        ]))
        ->assertOk()
        ->assertJsonPath('data.meta.postal_code', null)
        ->assertJsonPath('data.meta.postal_code_location_status', 'missing')
        ->assertJsonPath('data.branches.0.distanceKm', null);

    $this->assertDatabaseHas('laboratory_checkout_drafts', [
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB->value,
        'postal_code' => null,
        'selected_laboratory_store_id' => null,
    ]);

    $this->actingAs($user)
        ->postJson(route('laboratory.checkout.selected-store.store', LaboratoryBrand::SWISSLAB), [
            'laboratory_store_id' => $store->id,
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '64000',
        ]))
        ->assertOk();

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'clear_postal_code' => true,
        ]))
        ->assertOk()
        ->assertJsonPath('data.meta.postal_code', null);

    $this->assertDatabaseHas('laboratory_checkout_drafts', [
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB->value,
        'postal_code' => null,
        'selected_laboratory_store_id' => $store->id,
    ]);

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
        ]))
        ->assertOk()
        ->assertJsonPath('data.meta.postal_code', null)
        ->assertJsonPath('data.meta.postal_code_location_status', 'missing')
        ->assertJsonPath('data.branches.0.distanceKm', null);
});

it('does not clear postal codes for other brands when clearing one brand draft', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo', LaboratoryBrand::OLAB));
    compatibleCartItem($user, compatibleStudy('GLUCOSA SWISS', 'Sanguíneo', LaboratoryBrand::SWISSLAB));

    \App\Models\LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB,
        'postal_code' => '64000',
    ]);
    \App\Models\LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        'postal_code' => '03100',
    ]);

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'clear_postal_code' => true,
        ]))
        ->assertOk();

    $this->assertDatabaseHas('laboratory_checkout_drafts', [
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB->value,
        'postal_code' => null,
    ]);
    $this->assertDatabaseHas('laboratory_checkout_drafts', [
        'customer_id' => $user->customer->id,
        'laboratory_brand' => LaboratoryBrand::OLAB->value,
        'postal_code' => '64000',
    ]);
});

it('does not clear postal codes for other customers when clearing one customer draft', function (): void {
    $firstUser = compatibleStoresUser();
    $secondUser = compatibleStoresUser();
    compatibleCartItem($firstUser, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));
    compatibleCartItem($secondUser, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));

    \App\Models\LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $firstUser->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        'postal_code' => '03100',
    ]);
    \App\Models\LaboratoryCheckoutDraft::query()->create([
        'customer_id' => $secondUser->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB,
        'postal_code' => '64000',
    ]);

    $this->actingAs($firstUser)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'clear_postal_code' => true,
        ]))
        ->assertOk();

    $this->assertDatabaseHas('laboratory_checkout_drafts', [
        'customer_id' => $firstUser->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB->value,
        'postal_code' => null,
    ]);
    $this->assertDatabaseHas('laboratory_checkout_drafts', [
        'customer_id' => $secondUser->customer->id,
        'laboratory_brand' => LaboratoryBrand::SWISSLAB->value,
        'postal_code' => '64000',
    ]);
});

it('rejects clearing and setting a postal code in the same request', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '03100',
            'clear_postal_code' => true,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('postal_code');
});

it('resolves distance from postal code locations even when the brand has no store in that postal code', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo', LaboratoryBrand::OLAB));
    postalCodeLocation('64000', '25.671400', '-100.309000');

    compatibleStore('OLAB Cerca', LaboratoryBrand::OLAB, ['laboratorio'], latitude: '25.6750000', longitude: '-100.3090000', postalCode: '64010');
    compatibleStore('OLAB Lejos', LaboratoryBrand::OLAB, ['laboratorio'], latitude: '25.7500000', longitude: '-100.3500000', postalCode: '64020');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::OLAB->value,
            'postal_code' => '64000',
        ]))
        ->assertOk()
        ->assertJsonPath('data.meta.postal_code_location_status', 'resolved')
        ->assertJsonPath('data.branches.0.name', 'OLAB Cerca')
        ->assertJsonPath('data.branches.0.isCompatible', true)
        ->assertJsonPath('data.branches.1.name', 'OLAB Lejos')
        ->assertJsonPath('data.branches.1.isCompatible', true);
});

it('returns unresolved when a postal code is not in the local location catalog', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));
    compatibleStore('Swisslab Lab', LaboratoryBrand::SWISSLAB, ['laboratorio'], postalCode: '64000');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '64000',
        ]))
        ->assertOk()
        ->assertJsonPath('data.meta.postal_code_location_status', 'unresolved')
        ->assertJsonPath('data.branches.0.distanceKm', null);
});

it('preserves postal codes with leading zeroes as strings', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));
    postalCodeLocation('03100', '19.390230', '-99.174030');
    compatibleStore('Swisslab Lab', LaboratoryBrand::SWISSLAB, ['laboratorio'], postalCode: '03100');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '03100',
        ]))
        ->assertOk()
        ->assertJsonPath('data.meta.postal_code', '03100');
});

it('does not use another brand store in the same postal code for ranking or recommendations', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo', LaboratoryBrand::OLAB));
    postalCodeLocation('64000', '25.671400', '-100.309000');

    compatibleStore('OLAB Compatible', LaboratoryBrand::OLAB, ['laboratorio'], latitude: '25.6900000', longitude: '-100.3090000', postalCode: '64010');
    compatibleStore('Swisslab Mas Cerca', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '25.6715000', longitude: '-100.3090000', postalCode: '64000');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::OLAB->value,
            'postal_code' => '64000',
        ]))
        ->assertOk()
        ->assertJsonPath('data.branches.0.name', 'OLAB Compatible')
        ->assertJsonCount(1, 'data.branches')
        ->assertJsonMissing(['name' => 'Swisslab Mas Cerca']);
});

it('keeps incompatible nearer stores behind compatible farther stores', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));
    postalCodeLocation('64000', '25.671400', '-100.309000');

    compatibleStore('Incompatible Cerca', LaboratoryBrand::SWISSLAB, ['tomografia'], latitude: '25.6715000', longitude: '-100.3090000', postalCode: '64000');
    compatibleStore('Compatible Lejos', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '25.7000000', longitude: '-100.3090000', postalCode: '64020');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '64000',
        ]))
        ->assertOk()
        ->assertJsonPath('data.branches.0.name', 'Compatible Lejos')
        ->assertJsonPath('data.branches.0.isCompatible', true)
        ->assertJsonPath('data.branches.1.name', 'Incompatible Cerca')
        ->assertJsonPath('data.branches.1.isCompatible', false);
});

it('keeps stores without coordinates after compatible stores with distance', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));
    postalCodeLocation('64000', '25.671400', '-100.309000');

    compatibleStore('Con Coordenadas', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '25.7000000', longitude: '-100.3090000', postalCode: '64020');
    compatibleStore('Sin Coordenadas', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: null, longitude: null, postalCode: '64030');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '64000',
        ]))
        ->assertOk()
        ->assertJsonPath('data.branches.0.name', 'Con Coordenadas')
        ->assertJsonPath('data.branches.1.name', 'Sin Coordenadas')
        ->assertJsonPath('data.branches.1.distanceKm', null);
});

it('excludes soft deleted and inactive stores from recommendations', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));
    postalCodeLocation('64000', '25.671400', '-100.309000');

    compatibleStore('Activa', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '25.7000000', longitude: '-100.3090000');
    compatibleStore('Inactiva', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '25.6800000', longitude: '-100.3090000')
        ->update(['is_active' => false]);
    compatibleStore('Eliminada', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '25.6750000', longitude: '-100.3090000')
        ->delete();

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '64000',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data.branches')
        ->assertJsonPath('data.branches.0.name', 'Activa');
});

it('updates distance ranking when the postal code changes', function (): void {
    $user = compatibleStoresUser();
    compatibleCartItem($user, compatibleStudy('GLUCOSA EN SANGRE', 'Sanguíneo'));
    postalCodeLocation('64000', '25.671400', '-100.309000');
    postalCodeLocation('44100', '20.676700', '-103.347500');
    compatibleStore('Monterrey', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '25.6715000', longitude: '-100.3090000');
    compatibleStore('Guadalajara', LaboratoryBrand::SWISSLAB, ['laboratorio'], latitude: '20.6768000', longitude: '-103.3475000');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '64000',
        ]))
        ->assertOk()
        ->assertJsonPath('data.branches.0.name', 'Monterrey');

    $this->actingAs($user)
        ->getJson(route('laboratory.cart.compatible-stores', [
            'brand' => LaboratoryBrand::SWISSLAB->value,
            'postal_code' => '44100',
        ]))
        ->assertOk()
        ->assertJsonPath('data.branches.0.name', 'Guadalajara')
        ->assertJsonPath('data.meta.postal_code', '44100');
});

function compatibleStoresUser(): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);
}

function seedCompatibleStoreCapabilities(): void
{
    collect([
        'laboratorio' => 'Laboratorio',
        'tomografia' => 'Tomografia',
        'papanicolaou' => 'Papanicolaou',
        'ultrasonido_convencional' => 'Ultrasonido Convencional',
        'ultrasonido_especial' => 'Ultrasonido Especial',
    ])->each(fn (string $name, string $slug) => LaboratoryCapability::query()->create([
        'slug' => $slug,
        'name' => $name,
        'is_active' => true,
    ]));
}

function compatibleStudy(
    string $name,
    string $categoryName,
    LaboratoryBrand $brand = LaboratoryBrand::SWISSLAB,
): LaboratoryTest {
    $category = LaboratoryTestCategory::query()->firstOrCreate(['name' => $categoryName]);

    return LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'gda_id' => fake()->unique()->numerify('7#####'),
        'name' => $name,
        'laboratory_test_category_id' => $category->id,
    ]);
}

function compatiblePackage(string $name, array $featureList): LaboratoryTest
{
    $category = LaboratoryTestCategory::query()->firstOrCreate(['name' => 'Chequeos y Paquetes']);

    return LaboratoryTest::factory()->create([
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'gda_id' => fake()->unique()->numerify('128###'),
        'name' => $name,
        'feature_list' => $featureList,
        'laboratory_test_category_id' => $category->id,
    ]);
}

function compatibleCartItem(User $user, LaboratoryTest $test): LaboratoryCartItem
{
    return LaboratoryCartItem::factory()->create([
        'customer_id' => $user->customer->id,
        'laboratory_test_id' => $test->id,
    ]);
}

function compatibleStore(
    string $name,
    LaboratoryBrand $brand,
    array $capabilitySlugs,
    ?string $latitude = '19.3902300',
    ?string $longitude = '-99.1740300',
    ?string $postalCode = null,
): LaboratoryStore {
    $store = LaboratoryStore::query()->create([
        'name' => $name,
        'brand' => $brand->value,
        'state' => 'Ciudad de Mexico',
        'address' => $name.' address',
        'weekly_hours' => '07:00-15:00',
        'saturday_hours' => '07:00-15:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.test',
        'is_active' => true,
        'phone' => '5512345678',
        'postal_code' => $postalCode,
        'latitude' => $latitude,
        'longitude' => $longitude,
    ]);

    $store->capabilities()->attach(
        LaboratoryCapability::query()->whereIn('slug', $capabilitySlugs)->pluck('id')->all(),
    );

    foreach (range(1, 7) as $day) {
        LaboratoryStoreHour::query()->create([
            'laboratory_store_id' => $store->id,
            'day_of_week' => $day,
            'opens_at' => $day === 7 ? null : '07:00:00',
            'closes_at' => $day === 7 ? null : '15:00:00',
            'is_closed' => $day === 7,
            'raw_text' => $day === 7 ? 'Cerrado' : '07:00-15:00',
        ]);
    }

    return $store;
}

function postalCodeLocation(
    string $postalCode,
    string $latitude,
    string $longitude,
    string $source = 'manual',
): PostalCodeLocation {
    return PostalCodeLocation::query()->create([
        'postal_code' => $postalCode,
        'state' => 'Nuevo Leon',
        'municipality' => 'Monterrey',
        'city' => 'Monterrey',
        'latitude' => $latitude,
        'longitude' => $longitude,
        'source' => $source,
        'confidence' => null,
    ]);
}
