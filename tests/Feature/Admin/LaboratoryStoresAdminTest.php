<?php

use App\Enums\LaboratoryBrand;
use App\Models\Administrator;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryCapability;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreHour;
use App\Models\LaboratoryStoreImportRow;
use App\Models\LaboratoryStoreManualAudit;
use App\Models\LaboratoryStoreService;
use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->withoutMiddleware(RequirePassword::class);
});

it('allows authorized administrators to list laboratory stores', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage']);
    adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB, [
        'state' => 'Ciudad de México',
        'municipality' => 'Benito Juárez',
    ]);

    $this->get(route('admin.laboratory-stores.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/LaboratoryStores')
            ->where('summary.active_stores_count', 1)
            ->where('laboratoryStores.data.0.name', 'MIXCOAC')
            ->where('laboratoryStores.data.0.status_label', 'Activa'));
});

it('shows summary counts for active inactive brands and data alerts', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage']);
    adminLaboratoryStore('OLAB ACTIVA', LaboratoryBrand::OLAB);
    adminLaboratoryStore('SWISSLAB INACTIVA', LaboratoryBrand::SWISSLAB, [
        'is_active' => false,
    ]);
    adminLaboratoryStore('OLAB HISTORICA', LaboratoryBrand::OLAB, [
        'source_missing_at' => now(),
    ]);

    $this->get(route('admin.laboratory-stores.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.active_stores_count', 2)
            ->where('summary.inactive_stores_count', 1)
            ->where('summary.brands_count', 2)
            ->where('summary.data_alerts_count', 1));
});

it('denies administrators without laboratory store permission', function () {
    adminLaboratoryStoresUser();

    $this->get(route('admin.laboratory-stores.index'))->assertForbidden();
});

it('registers laboratory store permissions and attaches them to administrator role', function () {
    $permissions = Permission::query()
        ->whereIn('name', ['laboratory-stores.manage', 'laboratory-stores.manage.edit'])
        ->pluck('name')
        ->all();

    expect($permissions)->toContain('laboratory-stores.manage')
        ->and($permissions)->toContain('laboratory-stores.manage.edit')
        ->and(\App\Models\Role::where('name', 'Administrador')->first()?->hasPermissionTo('laboratory-stores.manage'))->toBeTrue()
        ->and(\App\Models\Role::where('name', 'Administrador')->first()?->hasPermissionTo('laboratory-stores.manage.edit'))->toBeTrue();
});

it('shows laboratory stores navigation when the administrator has permission', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage']);

    $response = $this->get(route('admin.admin'))->assertOk();

    expect(adminNavigationLabels($response))->toContain('Sucursales');
});

it('hides laboratory stores navigation when the administrator lacks permission', function () {
    adminLaboratoryStoresUser(['laboratory-purchases.manage']);

    $response = $this->get(route('admin.admin'))->assertOk();

    expect(adminNavigationLabels($response))->toContain('Pedidos')
        ->and(adminNavigationLabels($response))->not->toContain('Sucursales');
});

it('searches and filters laboratory stores by brand and state', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage']);
    adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB, [
        'state' => 'Ciudad de México',
        'municipality' => 'Benito Juárez',
        'neighborhood' => 'Mixcoac',
    ]);
    adminLaboratoryStore('SAN JERONIMO', LaboratoryBrand::SWISSLAB, [
        'state' => 'Nuevo Leon',
        'municipality' => 'Monterrey',
        'neighborhood' => 'San Jerónimo',
    ]);

    $this->get(route('admin.laboratory-stores.index', [
        'search' => 'mixcoac',
        'brand' => LaboratoryBrand::OLAB->value,
        'state' => 'Ciudad de México',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores.data', 1)
            ->where('laboratoryStores.data.0.name', 'MIXCOAC')
            ->where('filters.brand', LaboratoryBrand::OLAB->value)
            ->where('filters.state', 'Ciudad de México'));
});

it('filters laboratory stores by data quality state', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage']);
    adminLaboratoryStore('OK STORE', LaboratoryBrand::OLAB);
    adminLaboratoryStore('HISTORICAL STORE', LaboratoryBrand::OLAB, [
        'source_missing_at' => now(),
    ]);
    $conflictStore = adminLaboratoryStore('CONFLICT STORE', LaboratoryBrand::SWISSLAB);
    $warningStore = adminLaboratoryStore('WARNING STORE', LaboratoryBrand::SWISSLAB);

    LaboratoryStoreImportRow::query()->create([
        'run_id' => adminLaboratoryStoreImportRunId(),
        'excel_sheet' => 'SUCURSALES',
        'excel_row' => 20,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'source_name' => 'CONFLICT STORE',
        'normalized_name' => 'conflict store',
        'matched_store_id' => $conflictStore->id,
        'classification' => 'MATCHED',
        'action' => 'UPDATE',
        'planned_payload' => [
            'field_conflicts' => [
                'postal_code' => ['source_value' => '64000', 'existing_value' => '03940'],
            ],
        ],
    ]);
    LaboratoryStoreImportRow::query()->create([
        'run_id' => adminLaboratoryStoreImportRunId('warning-hash'),
        'excel_sheet' => 'SUCURSALES',
        'excel_row' => 21,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'source_name' => 'WARNING STORE',
        'normalized_name' => 'warning store',
        'matched_store_id' => $warningStore->id,
        'classification' => 'MATCHED',
        'action' => 'UPDATE',
        'planned_payload' => [],
        'warnings' => ['missing_phone'],
    ]);

    $this->get(route('admin.laboratory-stores.index', ['data_status' => 'conflict']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores.data', 1)
            ->where('laboratoryStores.data.0.name', 'CONFLICT STORE')
            ->where('laboratoryStores.data.0.data_quality.value', 'conflict'));

    $this->get(route('admin.laboratory-stores.index', ['data_status' => 'historical']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores.data', 1)
            ->where('laboratoryStores.data.0.name', 'HISTORICAL STORE')
            ->where('laboratoryStores.data.0.data_quality.value', 'historical'));
});

it('builds map payload from all filtered results instead of the paginated page', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage']);

    foreach (range(1, 16) as $index) {
        adminLaboratoryStore("OLAB MAP {$index}", LaboratoryBrand::OLAB, [
            'latitude' => 19.30 + ($index / 1000),
            'longitude' => -99.10 - ($index / 1000),
        ]);
    }

    adminLaboratoryStore('OLAB WITHOUT COORDS', LaboratoryBrand::OLAB, [
        'latitude' => null,
        'longitude' => null,
    ]);
    adminLaboratoryStore('SWISSLAB MAP', LaboratoryBrand::SWISSLAB, [
        'latitude' => 25.68,
        'longitude' => -100.31,
    ]);

    $this->get(route('admin.laboratory-stores.index', [
        'brand' => LaboratoryBrand::OLAB->value,
        'view' => 'map',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('laboratoryStores.data', 15)
            ->has('mapStores', 17)
            ->where('mapSummary.total', 17)
            ->where('mapSummary.with_coordinates', 16)
            ->where('mapSummary.missing_coordinates', 1)
            ->where('filters.view', 'map'));
});

it('filters map payload by location status state and active status', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage']);
    adminLaboratoryStore('CDMX WITH COORDS', LaboratoryBrand::OLAB, [
        'state' => 'Ciudad de México',
        'latitude' => 19.4,
        'longitude' => -99.1,
    ]);
    adminLaboratoryStore('CDMX WITHOUT COORDS', LaboratoryBrand::OLAB, [
        'state' => 'Ciudad de México',
        'latitude' => null,
        'longitude' => null,
    ]);
    adminLaboratoryStore('NL INACTIVE WITHOUT COORDS', LaboratoryBrand::OLAB, [
        'state' => 'Nuevo Leon',
        'is_active' => false,
        'latitude' => null,
        'longitude' => null,
    ]);

    $this->get(route('admin.laboratory-stores.index', [
        'state' => 'Nuevo Leon',
        'active_status' => 'inactive',
        'location_status' => 'missing_coordinates',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('mapStores', 1)
            ->where('mapStores.0.name', 'NL INACTIVE WITHOUT COORDS')
            ->where('mapStores.0.has_coordinates', false)
            ->where('mapSummary.total', 1)
            ->where('mapSummary.with_coordinates', 0)
            ->where('mapSummary.missing_coordinates', 1)
            ->where('filters.location_status', 'missing_coordinates'));
});

it('renders store detail with hours capabilities services and field conflicts', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage']);
    $store = adminLaboratoryStore('SAN JERONIMO', LaboratoryBrand::SWISSLAB, [
        'source_missing_at' => now(),
    ]);
    LaboratoryStoreHour::query()->create([
        'laboratory_store_id' => $store->id,
        'day_of_week' => 1,
        'opens_at' => '07:00:00',
        'closes_at' => '15:00:00',
        'is_closed' => false,
        'source' => 'gda',
    ]);
    $capability = LaboratoryCapability::query()->create([
        'slug' => 'ultrasonido',
        'name' => 'Ultrasonido',
        'sort_order' => 1,
        'is_active' => true,
    ]);
    $store->capabilities()->attach($capability);
    LaboratoryStoreService::query()->create([
        'laboratory_store_id' => $store->id,
        'service_type' => 'clinical_history',
        'name' => 'Historia Clínica',
        'is_active' => true,
        'source' => 'gda',
    ]);
    LaboratoryStoreImportRow::query()->create([
        'run_id' => adminLaboratoryStoreImportRunId(),
        'excel_sheet' => 'SUCURSALES',
        'excel_row' => 10,
        'brand' => LaboratoryBrand::SWISSLAB->value,
        'source_name' => 'SAN JERONIMO',
        'normalized_name' => 'san jeronimo',
        'matched_store_id' => $store->id,
        'classification' => 'MATCHED',
        'action' => 'UPDATE',
        'planned_payload' => [
            'field_conflicts' => [
                'postal_code' => [
                    'reason' => 'manual_value_present',
                    'source_value' => '64000',
                    'existing_value' => '03940',
                    'action' => 'SKIPPED_CONFLICT',
                ],
            ],
        ],
    ]);

    $this->get(route('admin.laboratory-stores.show', $store))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/LaboratoryStores')
            ->where('storeDetail.name', 'SAN JERONIMO')
            ->where('storeDetail.data_status_label', 'Histórica / No presente en fuente')
            ->where('storeDetail.hours.0.day_label', 'Lunes')
            ->where('storeDetail.capabilities.0.name', 'Ultrasonido')
            ->where('storeDetail.services.0.name', 'Historia Clínica')
            ->where('storeDetail.field_conflicts.0.field', 'postal_code')
            ->where('storeDetail.field_conflicts.0.source_value', '64000')
            ->where('storeDetail.field_conflicts.0.existing_value', '03940'));
});

it('updates safe basic fields without affecting readonly relationships', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage', 'laboratory-stores.manage.edit']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB);
    $capability = LaboratoryCapability::query()->create([
        'slug' => 'rayos-x',
        'name' => 'Rayos X',
        'is_active' => true,
    ]);
    $store->capabilities()->attach($capability);

    $this->patch(route('admin.laboratory-stores.update', $store), [
        ...adminLaboratoryStorePayload($store),
        'name' => 'MIXCOAC NORTE',
        'phone' => '55 4040 6580',
        'is_active' => false,
    ])->assertRedirect(route('admin.laboratory-stores.show', $store));

    expect($store->fresh()->name)->toBe('MIXCOAC NORTE')
        ->and($store->fresh()->is_active)->toBeFalse()
        ->and($store->capabilities()->count())->toBe(1)
        ->and(LaboratoryStoreManualAudit::query()
            ->where('laboratory_store_id', $store->id)
            ->where('scope', 'basic_fields')
            ->exists())->toBeTrue();
});

it('rejects invalid updates', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage', 'laboratory-stores.manage.edit']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB);

    $this->from(route('admin.laboratory-stores.show', $store))
        ->patch(route('admin.laboratory-stores.update', $store), [
            ...adminLaboratoryStorePayload($store),
            'name' => '',
            'postal_code' => 'abc',
            'latitude' => 100,
            'google_maps_url' => 'not-a-url',
        ])
        ->assertRedirect(route('admin.laboratory-stores.show', $store))
        ->assertSessionHasErrors(['name', 'postal_code', 'latitude', 'google_maps_url']);
});

it('updates coordinates as location audit without affecting services or appointments', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage', 'laboratory-stores.manage.edit']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB, [
        'latitude' => 19.377123,
        'longitude' => -99.188456,
    ]);
    LaboratoryStoreService::query()->create([
        'laboratory_store_id' => $store->id,
        'service_type' => 'clinical_history',
        'name' => 'Historia Clinica',
        'is_active' => true,
        'source' => 'gda',
    ]);
    $appointment = LaboratoryAppointment::factory()
        ->for(\App\Models\Customer::factory()->withRegularAccount())
        ->create([
            'brand' => LaboratoryBrand::OLAB->value,
            'laboratory_store_id' => $store->id,
        ]);

    $this->patch(route('admin.laboratory-stores.update', $store), [
        ...adminLaboratoryStorePayload($store),
        'latitude' => 19.401234,
        'longitude' => -99.145678,
    ])->assertRedirect(route('admin.laboratory-stores.show', $store));

    expect((float) $store->fresh()->latitude)->toBe(19.401234)
        ->and((float) $store->fresh()->longitude)->toBe(-99.145678)
        ->and($store->services()->count())->toBe(1)
        ->and($appointment->fresh()->laboratory_store_id)->toBe($store->id)
        ->and(LaboratoryStoreManualAudit::query()
            ->where('laboratory_store_id', $store->id)
            ->where('scope', 'location')
            ->exists())->toBeTrue();
});

it('updates normalized hours without writing legacy weekly hours', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage', 'laboratory-stores.manage.edit']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB, [
        'weekly_hours' => 'legacy text',
    ]);

    $this->patch(route('admin.laboratory-stores.hours.update', $store), [
        'hours' => adminLaboratoryStoreHoursPayload(),
    ])->assertRedirect(route('admin.laboratory-stores.show', $store));

    expect($store->hours()->count())->toBe(7)
        ->and($store->hours()->where('day_of_week', 1)->first()->opens_at->format('H:i:s'))->toBe('07:00:00')
        ->and($store->hours()->where('day_of_week', 7)->first()->is_closed)->toBeTrue()
        ->and($store->fresh()->weekly_hours)->toBe('legacy text')
        ->and(LaboratoryStoreManualAudit::query()
            ->where('laboratory_store_id', $store->id)
            ->where('scope', 'hours')
            ->exists())->toBeTrue();
});

it('rejects invalid normalized hours', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage', 'laboratory-stores.manage.edit']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB);
    $hours = adminLaboratoryStoreHoursPayload();
    $hours[0]['opens_at'] = '18:00';
    $hours[0]['closes_at'] = '08:00';

    $this->from(route('admin.laboratory-stores.show', $store))
        ->patch(route('admin.laboratory-stores.hours.update', $store), [
            'hours' => $hours,
        ])
        ->assertRedirect(route('admin.laboratory-stores.show', $store))
        ->assertSessionHasErrors(['hours.0.closes_at']);
});

it('syncs existing capabilities without creating new ones', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage', 'laboratory-stores.manage.edit']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB);
    $rayos = LaboratoryCapability::query()->create(['slug' => 'rayos-x', 'name' => 'Rayos X', 'is_active' => true]);
    $ultra = LaboratoryCapability::query()->create(['slug' => 'ultrasonido', 'name' => 'Ultrasonido', 'is_active' => true]);
    $store->capabilities()->attach($rayos);

    $this->patch(route('admin.laboratory-stores.capabilities.update', $store), [
        'capability_ids' => [$ultra->id],
    ])->assertRedirect(route('admin.laboratory-stores.show', $store));

    expect($store->capabilities()->pluck('laboratory_capabilities.id')->all())->toBe([$ultra->id])
        ->and(LaboratoryCapability::query()->count())->toBe(2)
        ->and(LaboratoryStoreManualAudit::query()
            ->where('laboratory_store_id', $store->id)
            ->where('scope', 'capabilities')
            ->exists())->toBeTrue();
});

it('rejects invalid capability ids', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage', 'laboratory-stores.manage.edit']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB);

    $this->from(route('admin.laboratory-stores.show', $store))
        ->patch(route('admin.laboratory-stores.capabilities.update', $store), [
            'capability_ids' => [999999],
        ])
        ->assertRedirect(route('admin.laboratory-stores.show', $store))
        ->assertSessionHasErrors(['capability_ids.0']);
});

it('activates and deactivates only supported services without deleting rows', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage', 'laboratory-stores.manage.edit']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB);
    LaboratoryStoreService::query()->create([
        'laboratory_store_id' => $store->id,
        'service_type' => 'clinical_history',
        'name' => 'Historia Clinica',
        'is_active' => false,
        'source' => 'gda',
    ]);

    $this->patch(route('admin.laboratory-stores.services.update', $store), [
        'services' => [
            ['service_type' => 'clinical_history', 'is_active' => true],
            ['service_type' => 'optical', 'is_active' => false],
        ],
    ])->assertRedirect(route('admin.laboratory-stores.show', $store));

    expect($store->services()->where('service_type', 'clinical_history')->first()->is_active)->toBeTrue()
        ->and($store->services()->where('service_type', 'optical')->first()->is_active)->toBeFalse()
        ->and($store->services()->count())->toBe(2)
        ->and(LaboratoryStoreManualAudit::query()
            ->where('laboratory_store_id', $store->id)
            ->where('scope', 'services')
            ->exists())->toBeTrue();
});

it('rejects unsupported service types', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage', 'laboratory-stores.manage.edit']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB);

    $this->from(route('admin.laboratory-stores.show', $store))
        ->patch(route('admin.laboratory-stores.services.update', $store), [
            'services' => [
                ['service_type' => 'parking', 'is_active' => true],
            ],
        ])
        ->assertRedirect(route('admin.laboratory-stores.show', $store))
        ->assertSessionHasErrors(['services.0.service_type']);
});

it('shows appointment count before deactivation and does not change appointments', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage', 'laboratory-stores.manage.edit']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB);
    $appointment = LaboratoryAppointment::factory()
        ->for(\App\Models\Customer::factory()->withRegularAccount())
        ->create([
            'brand' => LaboratoryBrand::OLAB->value,
            'laboratory_store_id' => $store->id,
        ]);

    $this->get(route('admin.laboratory-stores.show', $store))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('storeDetail.laboratory_appointments_count', 1));

    $this->delete(route('admin.laboratory-stores.destroy', $store))
        ->assertRedirect(route('admin.laboratory-stores.index'));

    expect($appointment->fresh()->laboratory_store_id)->toBe($store->id)
        ->and(LaboratoryAppointment::query()->count())->toBe(1)
        ->and(LaboratoryStoreManualAudit::query()
            ->where('laboratory_store_id', $store->id)
            ->where('scope', 'status')
            ->exists())->toBeTrue();
});

it('denies write actions without edit permission', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB);

    $this->patch(route('admin.laboratory-stores.update', $store), adminLaboratoryStorePayload($store))->assertForbidden();
    $this->patch(route('admin.laboratory-stores.hours.update', $store), ['hours' => adminLaboratoryStoreHoursPayload()])->assertForbidden();
    $this->patch(route('admin.laboratory-stores.capabilities.update', $store), ['capability_ids' => []])->assertForbidden();
    $this->patch(route('admin.laboratory-stores.services.update', $store), ['services' => []])->assertForbidden();
    $this->delete(route('admin.laboratory-stores.destroy', $store))->assertForbidden();
});

it('soft deletes and restores laboratory stores without hard deleting them', function () {
    adminLaboratoryStoresUser(['laboratory-stores.manage', 'laboratory-stores.manage.edit']);
    $store = adminLaboratoryStore('MIXCOAC', LaboratoryBrand::OLAB);

    $this->delete(route('admin.laboratory-stores.destroy', $store))
        ->assertRedirect(route('admin.laboratory-stores.index'));

    expect(LaboratoryStore::withTrashed()->find($store->id))->not->toBeNull()
        ->and(LaboratoryStore::withTrashed()->find($store->id)->trashed())->toBeTrue();

    $this->post(route('admin.laboratory-stores.restore', $store->id))
        ->assertRedirect(route('admin.laboratory-stores.show', $store->id));

    expect($store->fresh()->trashed())->toBeFalse();
});

function adminLaboratoryStoresUser(array $permissions = []): User
{
    $user = User::factory()->create();
    $administrator = Administrator::factory()->for($user)->create();

    foreach ($permissions as $permissionName) {
        $administrator->givePermissionTo(Permission::firstOrCreate([
            'name' => $permissionName,
            'guard_name' => 'web',
        ]));
    }

    test()->actingAs($user->fresh('administrator'));

    return $user;
}

function adminLaboratoryStore(string $name, LaboratoryBrand $brand, array $overrides = []): LaboratoryStore
{
    return LaboratoryStore::query()->create([
        ...[
            'name' => $name,
            'brand' => $brand,
            'source' => 'gda',
            'external_key' => strtolower($brand->value.'-'.$name),
            'state' => 'Ciudad de México',
            'address' => "{$name}, Ciudad de México",
            'street' => 'Av. Principal',
            'exterior_number' => '123',
            'interior_number' => null,
            'neighborhood' => 'Centro',
            'municipality' => 'Benito Juárez',
            'city' => 'Ciudad de México',
            'postal_code' => '03940',
            'phone' => '5540406580',
            'weekly_hours' => '07:00-15:00',
            'saturday_hours' => '07:00-15:00',
            'sunday_hours' => 'Cerrado',
            'google_maps_url' => 'https://maps.example.test',
            'latitude' => 19.377123,
            'longitude' => -99.188456,
            'is_active' => true,
        ],
        ...$overrides,
    ]);
}

function adminLaboratoryStorePayload(LaboratoryStore $store): array
{
    return [
        'name' => $store->name,
        'phone' => $store->phone,
        'address' => $store->address,
        'street' => $store->street,
        'exterior_number' => $store->exterior_number,
        'interior_number' => $store->interior_number,
        'neighborhood' => $store->neighborhood,
        'municipality' => $store->municipality,
        'city' => $store->city,
        'state' => $store->state,
        'postal_code' => $store->postal_code,
        'google_maps_url' => $store->google_maps_url,
        'latitude' => $store->latitude,
        'longitude' => $store->longitude,
        'is_active' => $store->is_active,
    ];
}

function adminLaboratoryStoreHoursPayload(): array
{
    return collect(range(1, 7))->map(fn (int $day) => [
        'day_of_week' => $day,
        'is_closed' => $day === 7,
        'opens_at' => $day === 7 ? null : '07:00',
        'closes_at' => $day === 7 ? null : '15:00',
    ])->all();
}

function adminNavigationLabels($response): array
{
    $sections = $response->original->getData()['page']['props']['adminNavigation'] ?? [];

    return collect($sections)
        ->pluck('items')
        ->flatten(1)
        ->flatMap(function (array $item) {
            return collect([$item['label'] ?? null])
                ->merge(collect($item['items'] ?? [])->pluck('label'));
        })
        ->filter()
        ->values()
        ->all();
}

function adminLaboratoryStoreImportRunId(string $fileHash = 'test-hash'): int
{
    return DB::table('laboratory_store_import_runs')->insertGetId([
        'file_path' => 'stores.xlsx',
        'file_hash' => $fileHash,
        'dry_run' => false,
        'status' => 'completed',
        'totals' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
