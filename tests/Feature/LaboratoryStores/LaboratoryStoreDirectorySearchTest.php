<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreHour;
use App\Models\LaboratoryStoreService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03 10:00:00', 'America/Mexico_City'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('returns active stores scoped by brand with directory props', function () {
    [$narvarte, $mixcoac] = seedDirectoryStores();
    seedStore('Swisslab Centro', LaboratoryBrand::SWISSLAB, state: 'Nuevo Leon');

    $response = $this->get(route('laboratory-stores.index', ['brand' => 'olab']));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LaboratoryStores')
            ->has('laboratoryStores', 2)
            ->where('laboratoryStores.0.id', $mixcoac->id)
            ->where('laboratoryStores.0.brand', 'olab')
            ->where('laboratoryStores.0.today.opens_at', '07:00')
            ->where('laboratoryStores.0.today.closes_at', '15:00')
            ->where('laboratoryStores.0.today.is_closed', false)
            ->where('laboratoryStores.0.today.status', 'open')
            ->where('laboratoryStores.0.today.minutes_until_close', 300)
            ->where('laboratoryStores.0.today.day_of_week', 4)
            ->has('laboratoryStores.0.weekly_schedule', 7)
            ->where('laboratoryStores.0.weekly_schedule.0.label', 'Lunes')
            ->where('laboratoryStores.0.weekly_schedule.0.opens_at', '07:00')
            ->where('laboratoryStores.0.weekly_schedule.6.label', 'Domingo')
            ->where('laboratoryStores.0.weekly_schedule.6.is_closed', true)
            ->where('laboratoryStores.0.service_flags.has_clinical_history', false)
            ->where('laboratoryStores.1.id', $narvarte->id)
            ->where('filters.brand', 'olab')
            ->where('filters.sort', 'name')
            ->where('total', 2)
            ->where('filtered_total', 2)
            ->has('states', 1)
            ->has('municipalities', 1)
            ->has('capabilities')
            ->has('services'));
});

it('exposes closed and opens later states from Mexico City time', function () {
    seedDirectoryStores();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03 06:30:00', 'America/Mexico_City'));

    $this->get(route('laboratory-stores.index', ['brand' => 'olab']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('laboratoryStores.0.today.status', 'opens_later')
            ->where('laboratoryStores.0.today.minutes_until_close', null));

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 10:00:00', 'America/Mexico_City'));

    $this->get(route('laboratory-stores.index', ['brand' => 'olab']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('laboratoryStores.0.today.status', 'closed')
            ->where('laboratoryStores.0.today.is_closed', true)
            ->where('laboratoryStores.0.today.day_of_week', 7));
});

it('filters by search text and postal-code-like search', function () {
    seedDirectoryStores();

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'search' => 'narvarte']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 1)
            ->where('laboratoryStores.0.name', 'NARVARTE')
            ->where('filters.search', 'narvarte'));

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'search' => '03940']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 1)
            ->where('laboratoryStores.0.name', 'MIXCOAC'));
});

it('filters by state municipality and exact postal code', function () {
    seedDirectoryStores();
    seedStore('METEPEC', LaboratoryBrand::OLAB, state: 'Estado de Mexico', municipality: 'METEPEC', postalCode: '52140');

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'state' => 'Ciudad de Mexico']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 2)
            ->has('municipalities', 1)
            ->where('municipalities.0', 'Benito Juarez'));

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'municipality' => 'Benito Juarez']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('laboratoryStores', 2));

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'postal_code' => '03940']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 1)
            ->where('laboratoryStores.0.name', 'MIXCOAC'));
});

it('filters by capability and exposes scoped capability counts', function () {
    [$narvarte, $mixcoac] = seedDirectoryStores();
    $rayos = capability('rayos_x', 'Rayos X', 1);
    $resonancia = capability('resonancia_magnetica', 'Resonancia Magnetica', 2);
    $mastografia = capability('mastografia', 'Mastografia', 3);

    $narvarte->capabilities()->attach([$rayos->id, $resonancia->id]);
    $mixcoac->capabilities()->attach([$rayos->id, $mastografia->id]);

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'capability' => 'rayos_x']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 2)
            ->where('capabilities.0.slug', 'rayos_x')
            ->where('capabilities.0.stores_count', 2));

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'capability' => 'resonancia_magnetica']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 1)
            ->where('laboratoryStores.0.name', 'NARVARTE'));
});

it('can match capability aliases from search without replacing explicit capability filters', function () {
    [$narvarte] = seedDirectoryStores();
    $resonancia = capability('resonancia_magnetica', 'Resonancia Magnetica', 2);
    $narvarte->capabilities()->attach($resonancia->id);

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'search' => 'resonancia']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 1)
            ->where('laboratoryStores.0.name', 'NARVARTE'));
});

it('filters by special services and exposes service flags', function () {
    [$narvarte, $mixcoac] = seedDirectoryStores();

    service($narvarte, 'clinical_history');
    service($mixcoac, 'optical');

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'service' => 'historia_clinica']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 1)
            ->where('laboratoryStores.0.name', 'NARVARTE')
            ->where('laboratoryStores.0.service_flags.has_clinical_history', true)
            ->where('services.0.type', 'historia_clinica'));

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'service' => 'optica']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 1)
            ->where('laboratoryStores.0.name', 'MIXCOAC')
            ->where('laboratoryStores.0.service_flags.has_optical', true));
});

it('combines brand state and capability filters', function () {
    [$narvarte, $mixcoac] = seedDirectoryStores();
    $rayos = capability('rayos_x', 'Rayos X', 1);
    $mixcoac->capabilities()->attach($rayos->id);
    seedStore('Swisslab Rayos', LaboratoryBrand::SWISSLAB, state: 'Ciudad de Mexico')->capabilities()->attach($rayos->id);

    $this->get(route('laboratory-stores.index', [
        'brand' => 'olab',
        'state' => 'Ciudad de Mexico',
        'capability' => 'rayos_x',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 1)
            ->where('laboratoryStores.0.id', $mixcoac->id));
});

it('hides inactive and soft deleted stores', function () {
    seedDirectoryStores();
    seedStore('INACTIVA', LaboratoryBrand::OLAB, active: false);
    $deleted = seedStore('BORRADA', LaboratoryBrand::OLAB);
    $deleted->delete();

    $this->get(route('laboratory-stores.index', ['brand' => 'olab']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 2)
            ->where('total', 2)
            ->where('filtered_total', 2));
});

it('sorts by name and accepts relevance sort', function () {
    seedStore('ZETA', LaboratoryBrand::OLAB);
    seedStore('ALFA', LaboratoryBrand::OLAB);

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'sort' => 'name']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('laboratoryStores.0.name', 'ALFA')
            ->where('laboratoryStores.1.name', 'ZETA'));

    $this->get(route('laboratory-stores.index', ['brand' => 'olab', 'search' => 'ZETA', 'sort' => 'relevance']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('laboratoryStores.0.name', 'ZETA')
            ->where('filters.sort', 'relevance'));
});

it('validates unknown and invalid filters', function () {
    $this->from(route('laboratory-stores.index'))
        ->get(route('laboratory-stores.index', ['brand' => 'unknown']))
        ->assertSessionHasErrors('brand');

    $this->from(route('laboratory-stores.index'))
        ->get(route('laboratory-stores.index', ['capability' => 'unknown_capability']))
        ->assertSessionHasErrors('capability');

    $this->from(route('laboratory-stores.index'))
        ->get(route('laboratory-stores.index', ['service' => 'rayos']))
        ->assertSessionHasErrors('service');

    $this->from(route('laboratory-stores.index'))
        ->get(route('laboratory-stores.index', ['postal_code' => '3940']))
        ->assertSessionHasErrors('postal_code');
});

it('validates location filters and distance sorting requirements', function () {
    $this->from(route('laboratory-stores.index'))
        ->get(route('laboratory-stores.index', ['latitude' => '91', 'longitude' => '-99']))
        ->assertSessionHasErrors('latitude');

    $this->from(route('laboratory-stores.index'))
        ->get(route('laboratory-stores.index', ['latitude' => '19', 'longitude' => '-181']))
        ->assertSessionHasErrors('longitude');

    $this->from(route('laboratory-stores.index'))
        ->get(route('laboratory-stores.index', ['latitude' => '19']))
        ->assertSessionHasErrors(['latitude', 'longitude']);

    $this->from(route('laboratory-stores.index'))
        ->get(route('laboratory-stores.index', ['radius' => '15']))
        ->assertSessionHasErrors('radius');

    $this->from(route('laboratory-stores.index'))
        ->get(route('laboratory-stores.index', ['sort' => 'distance']))
        ->assertSessionHasErrors('sort');
});

it('orders by distance filters by radius and excludes stores without coordinates', function () {
    seedStore('NARVARTE', LaboratoryBrand::OLAB, latitude: '19.3902300', longitude: '-99.1740300');
    seedStore('MIXCOAC', LaboratoryBrand::OLAB, latitude: '19.3650650', longitude: '-99.1781010');
    seedStore('MONTERREY', LaboratoryBrand::OLAB, state: 'Nuevo Leon', municipality: 'Monterrey', latitude: '25.6866000', longitude: '-100.3161000');
    seedStore('SIN COORDENADAS', LaboratoryBrand::OLAB, latitude: null, longitude: null);

    $response = $this->get(route('laboratory-stores.index', [
        'brand' => 'olab',
        'latitude' => '19.3902300',
        'longitude' => '-99.1740300',
        'radius' => '5',
        'sort' => 'distance',
    ]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 2)
            ->where('laboratoryStores.0.name', 'NARVARTE')
            ->where('laboratoryStores.0.distance_km', 0)
            ->where('laboratoryStores.1.name', 'MIXCOAC')
            ->where('filters.radius', 5)
            ->where('filters.sort', 'distance'));
});

it('combines capability state and distance filters', function () {
    $near = seedStore('MIXCOAC', LaboratoryBrand::OLAB, state: 'Ciudad de Mexico', latitude: '19.3650650', longitude: '-99.1781010');
    $sameStateWithoutCapability = seedStore('NARVARTE', LaboratoryBrand::OLAB, state: 'Ciudad de Mexico', latitude: '19.3902300', longitude: '-99.1740300');
    $far = seedStore('TOLUCA', LaboratoryBrand::OLAB, state: 'Estado de Mexico', municipality: 'Toluca', latitude: '19.2826000', longitude: '-99.6557000');
    $mastografia = capability('mastografia', 'Mastografia', 1);

    $near->capabilities()->attach($mastografia->id);
    $far->capabilities()->attach($mastografia->id);
    $sameStateWithoutCapability->capabilities()->detach();

    $this->get(route('laboratory-stores.index', [
        'brand' => 'olab',
        'state' => 'Ciudad de Mexico',
        'capability' => 'mastografia',
        'latitude' => '19.3902300',
        'longitude' => '-99.1740300',
        'radius' => '10',
        'sort' => 'distance',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores', 1)
            ->where('laboratoryStores.0.name', 'MIXCOAC')
            ->where('filters.capability', 'mastografia')
            ->where('filters.state', 'Ciudad de Mexico'));
});

it('keeps query count stable with eager loaded relations', function () {
    [$narvarte, $mixcoac] = seedDirectoryStores();
    $rayos = capability('rayos_x', 'Rayos X', 1);
    $narvarte->capabilities()->attach($rayos->id);
    $mixcoac->capabilities()->attach($rayos->id);
    service($narvarte, 'clinical_history');
    service($mixcoac, 'optical');

    DB::enableQueryLog();

    $this->get(route('laboratory-stores.index', ['brand' => 'olab']))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(13);
});

function seedDirectoryStores(): array
{
    $narvarte = seedStore('NARVARTE', LaboratoryBrand::OLAB, postalCode: '03023', neighborhood: 'Narvarte');
    $mixcoac = seedStore('MIXCOAC', LaboratoryBrand::OLAB, postalCode: '03940', neighborhood: 'Mixcoac');

    return [$narvarte, $mixcoac];
}

function seedStore(
    string $name,
    LaboratoryBrand $brand,
    string $state = 'Ciudad de Mexico',
    string $municipality = 'Benito Juarez',
    string $postalCode = '03940',
    bool $active = true,
    string $neighborhood = 'Centro',
    ?string $latitude = '19.3902300',
    ?string $longitude = '-99.1740300',
): LaboratoryStore {
    $store = LaboratoryStore::query()->create([
        'name' => $name,
        'brand' => $brand,
        'state' => $state,
        'address' => "{$name} Calle Principal, {$municipality}, {$postalCode}",
        'weekly_hours' => '07:00-15:00',
        'saturday_hours' => '07:00-15:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query='.urlencode($name),
        'is_active' => $active,
        'street' => 'Calle Principal',
        'neighborhood' => $neighborhood,
        'municipality' => $municipality,
        'city' => 'Ciudad de Mexico',
        'postal_code' => $postalCode,
        'phone' => '5512345678',
        'latitude' => $latitude,
        'longitude' => $longitude,
    ]);

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

function capability(string $slug, string $name, int $sortOrder): LaboratoryCapability
{
    return LaboratoryCapability::query()->create([
        'slug' => $slug,
        'name' => $name,
        'sort_order' => $sortOrder,
        'is_active' => true,
    ]);
}

function service(LaboratoryStore $store, string $type): LaboratoryStoreService
{
    return LaboratoryStoreService::query()->create([
        'laboratory_store_id' => $store->id,
        'service_type' => $type,
        'name' => $type === 'clinical_history' ? 'Historia Clinica' : 'Optica',
        'is_active' => true,
    ]);
}
