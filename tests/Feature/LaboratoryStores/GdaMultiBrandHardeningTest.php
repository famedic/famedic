<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreImportRow;
use App\Models\LaboratoryStoreImportRun;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function gdaHardeningFixturePath(array $directoryRows, array $clinicalRows = [], array $opticalRows = []): string
{
    $path = storage_path('app/gda-hardening-fixture-'.strtolower((string) str()->uuid()).'.xlsx');
    $spreadsheet = new Spreadsheet;

    $directory = $spreadsheet->getActiveSheet();
    $directory->setTitle('DIRECTORIO');
    $directory->fromArray([
        [''],
        [''],
        [''],
        ['MARCA', 'SUCURSAL', 'ESTADO', 'CALLE', 'NO. EXT', 'COLONIA', 'MUNICIPIO', 'CIUDAD', 'CP', 'TELEFONO', 'LATITUD', 'LONGITUD', 'HORARIOS', 'LABORATORIO'],
        ...$directoryRows,
    ]);

    $clinical = $spreadsheet->createSheet();
    $clinical->setTitle('HISTORIA CLINICO');
    $clinical->fromArray([
        ['MARCA', 'SUCURSAL', 'MEDICO', 'HORARIO'],
        ...$clinicalRows,
    ]);

    $optical = $spreadsheet->createSheet();
    $optical->setTitle('OPTICAS');
    $optical->fromArray([
        ['MARCA', 'SUCURSAL', 'DIRECCION', 'TELEFONO'],
        ...$opticalRows,
    ]);

    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function gdaHardeningStore(string $brand, string $name, array $overrides = []): LaboratoryStore
{
    return LaboratoryStore::query()->create(array_merge([
        'brand' => $brand,
        'name' => $name,
        'state' => 'Nuevo Leon',
        'address' => 'Existing address',
        'street' => 'Existing street',
        'exterior_number' => '1',
        'neighborhood' => 'Existing neighborhood',
        'municipality' => 'Monterrey',
        'city' => 'Monterrey',
        'postal_code' => '64000',
        'phone' => '8100000000',
        'latitude' => '25.6000000',
        'longitude' => '-100.3000000',
        'weekly_hours' => 'Lunes a Viernes de 8:00 a 17:00',
        'saturday_hours' => 'Sabado de 8:00 a 12:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example/existing',
    ], $overrides));
}

function gdaHardeningRow(string $brand, string $name, array $overrides = []): array
{
    return array_replace([
        $brand,
        $name,
        'Nuevo Leon',
        'Calle Norte',
        '100',
        'Centro',
        'Monterrey',
        'Monterrey',
        '64000',
        '8111111111',
        '25.670000',
        '-100.310000',
        'LUNES A VIERNES: 08:00 A 17:00',
        'a',
    ], $overrides);
}

function gdaHardeningCompletedRun(array $rows, string $brandFilter): array
{
    $path = gdaHardeningFixturePath($rows);
    $hash = hash_file('sha256', $path);

    test()->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--dry-run' => true,
        '--brand' => $brandFilter,
    ])->assertSuccessful();

    return [$path, $hash, LaboratoryStoreImportRun::query()->latest('id')->firstOrFail()];
}

it('allows swisslab apply with the exact SWISSLAB confirmation token', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    $store = gdaHardeningStore(LaboratoryBrand::SWISSLAB->value, 'MONTERREY');
    [$path, $hash, $run] = gdaHardeningCompletedRun([
        gdaHardeningRow('SWISSLAB', 'MONTERREY', [3 => 'Calle Actualizada', 8 => '64620', 9 => '8122222222']),
    ], 'swisslab');

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'swisslab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'SWISSLAB',
    ])->assertSuccessful();

    expect($store->refresh()->address)->toContain('Calle Actualizada')
        ->and($store->phone)->toBe('8122222222');
});

it('blocks swisslab apply with an OLAB confirmation token', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    gdaHardeningStore(LaboratoryBrand::SWISSLAB->value, 'MONTERREY');
    [$path, $hash, $run] = gdaHardeningCompletedRun([
        gdaHardeningRow('SWISSLAB', 'MONTERREY'),
    ], 'swisslab');

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'swisslab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'OLAB',
    ])
        ->expectsOutputToContain('--confirm-apply must be SWISSLAB for --brand=swisslab')
        ->assertFailed();
});

it('blocks unsupported apply brands', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    $path = gdaHardeningFixturePath([
        gdaHardeningRow('SWISSLAB', 'MONTERREY'),
    ]);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'unknown',
        '--run-id' => '1',
        '--confirm-hash' => hash_file('sha256', $path),
        '--confirm-apply' => 'UNKNOWN',
    ])
        ->expectsOutputToContain('--apply requires --brand to be one of: olab, swisslab, jenner, liacsa, azteca')
        ->assertFailed();
});

it('blocks apply when the completed run belongs to a different brand', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    gdaHardeningStore(LaboratoryBrand::JENNER->value, 'CULHUACAN', ['state' => 'Ciudad de Mexico']);
    [$path, $hash, $run] = gdaHardeningCompletedRun([
        gdaHardeningRow('JENNER', 'CULHUACAN', [2 => 'Ciudad de Mexico', 8 => '04480']),
    ], 'jenner');

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'swisslab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'SWISSLAB',
    ])
        ->expectsOutputToContain('WRONG_BRAND')
        ->assertFailed();
});

it('blocks apply when a run contains mixed brand rows', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    gdaHardeningStore(LaboratoryBrand::SWISSLAB->value, 'MONTERREY');
    [$path, $hash, $run] = gdaHardeningCompletedRun([
        gdaHardeningRow('SWISSLAB', 'MONTERREY'),
    ], 'swisslab');

    LaboratoryStoreImportRow::query()->where('run_id', $run->id)->firstOrFail()->update(['brand' => 'jenner']);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'swisslab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'SWISSLAB',
    ])
        ->expectsOutputToContain('WRONG_BRAND_ROWS')
        ->assertFailed();
});

it('blocks apply while the feature flag is disabled', function () {
    gdaHardeningStore(LaboratoryBrand::SWISSLAB->value, 'MONTERREY');
    [$path, $hash, $run] = gdaHardeningCompletedRun([
        gdaHardeningRow('SWISSLAB', 'MONTERREY'),
    ], 'swisslab');

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'swisslab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'SWISSLAB',
    ])
        ->expectsOutputToContain('Apply mode is disabled')
        ->assertFailed();
});

it('blocks apply without an explicit brand', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    $path = gdaHardeningFixturePath([
        gdaHardeningRow('SWISSLAB', 'MONTERREY'),
    ]);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--run-id' => '1',
        '--confirm-hash' => hash_file('sha256', $path),
        '--confirm-apply' => 'SWISSLAB',
    ])
        ->expectsOutputToContain('--apply requires --brand to be one of: olab, swisslab, jenner, liacsa, azteca')
        ->assertFailed();
});

it('blocks apply without the exact confirmation token', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    gdaHardeningStore(LaboratoryBrand::SWISSLAB->value, 'MONTERREY');
    [$path, $hash, $run] = gdaHardeningCompletedRun([
        gdaHardeningRow('SWISSLAB', 'MONTERREY'),
    ], 'swisslab');

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'swisslab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
    ])
        ->expectsOutputToContain('--confirm-apply must be SWISSLAB for --brand=swisslab')
        ->assertFailed();
});

it('skips only conflicting fields while allowing safe updates on a matched row', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    $store = gdaHardeningStore(LaboratoryBrand::SWISSLAB->value, 'SAN JERONIMO', [
        'address' => 'Existing San Jeronimo address',
        'neighborhood' => 'COLINAS DE SAN JERONIMO',
        'municipality' => 'MONTERREY',
        'postal_code' => '64630',
        'phone' => '8100000000',
    ]);

    [$path, $hash, $run] = gdaHardeningCompletedRun([
        gdaHardeningRow('SWISSLAB', 'SAN JERONIMO', [
            3 => 'Blvd Puerta del Sol',
            4 => '106',
            5 => 'PROGRESO TIZAPAN',
            6 => 'COLINAS DE SAN JERONIMO',
            8 => '01080',
            9 => '8151020830',
            10 => '25.672490',
            11 => '-100.370240',
        ]),
    ], 'swisslab');

    $planned = LaboratoryStoreImportRow::query()->where('run_id', $run->id)->firstOrFail()->planned_payload;

    expect($planned['skipped_fields'])->toContain('postal_code', 'neighborhood', 'municipality', 'address')
        ->and($planned['field_conflicts']['postal_code']['existing_value'])->toBe('64630')
        ->and($planned['field_conflicts']['postal_code']['action'])->toBe('SKIPPED_CONFLICT');

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'swisslab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'SWISSLAB',
    ])->assertSuccessful();

    $row = LaboratoryStoreImportRow::query()->where('run_id', $run->id)->firstOrFail();
    $after = $row->after_snapshot;

    expect($store->refresh()->postal_code)->toBe('64630')
        ->and($store->neighborhood)->toBe('COLINAS DE SAN JERONIMO')
        ->and($store->municipality)->toBe('MONTERREY')
        ->and($store->address)->toBe('Existing San Jeronimo address')
        ->and($store->phone)->toBe('8151020830')
        ->and((string) $store->latitude)->toBe('25.6724900')
        ->and((string) $store->longitude)->toBe('-100.3702400')
        ->and($after['field_safety']['skipped_conflicts']['postal_code']['existing_value'])->toBe('64630')
        ->and($after['field_safety']['skipped_conflicts']['postal_code']['action'])->toBe('SKIPPED_CONFLICT');
});

it('updates a valid same-id relocation without marking it as a conflict', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    $store = gdaHardeningStore(LaboratoryBrand::SWISSLAB->value, 'VIA CORDILLERA', [
        'address' => 'Old Via address',
        'postal_code' => '64000',
        'latitude' => '25.6000000',
        'longitude' => '-100.3000000',
    ]);

    [$path, $hash, $run] = gdaHardeningCompletedRun([
        gdaHardeningRow('SWISSLAB', 'VIA CORDILLERA', [
            3 => 'Via Cordillera',
            4 => '333',
            5 => 'Valle Poniente',
            6 => 'Santa Catarina',
            8 => '66196',
            10 => '25.657000',
            11 => '-100.438000',
        ]),
    ], 'swisslab');

    $planned = LaboratoryStoreImportRow::query()->where('run_id', $run->id)->firstOrFail()->planned_payload;

    expect($planned)->not->toHaveKey('field_conflicts')
        ->and($planned)->not->toHaveKey('skipped_fields');

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'swisslab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'SWISSLAB',
    ])->assertSuccessful();

    expect($store->refresh()->id)->toBe($store->id)
        ->and($store->address)->toContain('Via Cordillera')
        ->and($store->postal_code)->toBe('66196')
        ->and((string) $store->latitude)->toBe('25.6570000')
        ->and((string) $store->longitude)->toBe('-100.4380000');
});

it('links auxiliary service aliases to directory rows planned in the same run', function () {
    $path = gdaHardeningFixturePath([
        gdaHardeningRow('SWISSLAB', 'FRESNOS', [8 => '66635']),
    ], [], [
        ['SWISSLAB', 'FLN_FRESNOS', 'Av Afganistan, Magnolias', '8111111111'],
    ]);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--dry-run' => true,
        '--brand' => 'swisslab',
    ])->assertSuccessful();

    $optical = LaboratoryStoreImportRow::query()
        ->where('excel_sheet', 'OPTICAS')
        ->where('source_name', 'FLN_FRESNOS')
        ->firstOrFail();

    expect($optical->classification)->toBe(LaboratoryStoreImportRow::CLASSIFICATION_MATCHED)
        ->and($optical->action)->toBe(LaboratoryStoreImportRow::ACTION_UPDATE_CANDIDATE)
        ->and($optical->planned_payload['linked_directory_source_name'])->toBe('FRESNOS');
});

it('blocks apply when multiple directory rows target the same existing store id', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    $store = gdaHardeningStore(LaboratoryBrand::SWISSLAB->value, 'ACAPULCO', [
        'postal_code' => '67117',
    ]);
    $path = gdaHardeningFixturePath([
        gdaHardeningRow('SWISSLAB', 'ACAPULCO', [8 => '67117']),
        gdaHardeningRow('SWISSLAB', 'ARBOLEDAS', [8 => '67117']),
    ]);
    $hash = hash_file('sha256', $path);

    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'swisslab',
        '--store' => 'ARBOLEDAS',
        '--decision' => 'match',
        '--db-id' => (string) $store->id,
        '--file-hash' => $hash,
        '--notes' => 'business_confirmed_same_store',
    ])->assertSuccessful();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--dry-run' => true,
        '--brand' => 'swisslab',
    ])->assertSuccessful();

    $run = LaboratoryStoreImportRun::query()->latest('id')->firstOrFail();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'swisslab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'SWISSLAB',
    ])
        ->expectsOutputToContain('DUPLICATE_MATCHED_STORE')
        ->assertFailed();
});
