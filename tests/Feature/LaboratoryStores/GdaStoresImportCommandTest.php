<?php

use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryStoreImportResolution;
use App\Models\LaboratoryStoreImportRow;
use App\Models\LaboratoryStoreImportRun;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function gdaBusinessTableCounts(): array
{
    return [
        'laboratory_stores' => LaboratoryStore::query()->count(),
        'laboratory_store_hours' => \Illuminate\Support\Facades\DB::table('laboratory_store_hours')->count(),
        'laboratory_capabilities' => \Illuminate\Support\Facades\DB::table('laboratory_capabilities')->count(),
        'laboratory_store_capability' => \Illuminate\Support\Facades\DB::table('laboratory_store_capability')->count(),
        'laboratory_store_services' => \Illuminate\Support\Facades\DB::table('laboratory_store_services')->count(),
        'laboratory_appointments' => \Illuminate\Support\Facades\DB::table('laboratory_appointments')->count(),
    ];
}

function gdaFixturePath(): string
{
    $path = storage_path('app/gda-directory-fixture.xlsx');
    $spreadsheet = new Spreadsheet;

    $directory = $spreadsheet->getActiveSheet();
    $directory->setTitle('DIRECTORIO');
    $directory->fromArray([
        [''],
        [''],
        [''],
        ['MARCA', 'SUCURSAL', 'ESTADO', 'CALLE', 'NO. EXT', 'COLONIA', 'MUNICIPIO', 'CIUDAD', 'CP', 'TELEFONO', 'LATITUD', 'LONGITUD', 'HORARIOS', 'LABORATORIO', 'TOMOGRAFIA'],
        ['OLAB', 'CENTER PLAZA', 'Ciudad de México', 'Av Test', '123', 'Centro', 'Iztacalco', 'CDMX', '1010', '+52 55 1234 5678', '19134047', '-98771021', 'LUNES A VIERNES: 07:00 A 15:00 // SÁBADOS: 08:00 A 12:00 // DOMINGOS: N/A', 'a', ''],
        ['OLAB', 'ANZURES', 'Ciudad de México', 'Otra', '9', 'Anzures', 'Miguel Hidalgo', 'CDMX', '11590', '5511111111', '19.430000', '-99.180000', 'LUNES A VIERNES: 07:00 A 15:00', 'a', 'a'],
        ['OLAB', 'NUEVA SUCURSAL', 'Ciudad de México', 'Nueva', '10', 'Roma', 'Cuauhtemoc', 'CDMX', '6700', '5533333333', '19.410000', '-99.160000', 'LUNES A VIERNES: 07:00 A 15:00', 'a', ''],
        ['SWISSLAB', 'MONTERREY', 'Nuevo León', 'Calle Norte', '1', 'Centro', 'Monterrey', 'Monterrey', '64000', '8111111111', '25.670000', '-100.310000', 'LUNES A VIERNES: 08:00 A 17:00', 'a', ''],
    ]);

    $clinical = $spreadsheet->createSheet();
    $clinical->setTitle('HISTORIA CLINICO');
    $clinical->fromArray([
        ['MARCA', 'SUCURSAL', 'MEDICO', 'HORARIO'],
        ['OLAB', 'CENTER PLAZA', 'SIN MEDICO', 'N/A'],
        ['OLAB', 'NUEVA SUCURSAL', 'SIN MEDICO', 'LUNES A VIERNES'],
    ]);

    $optical = $spreadsheet->createSheet();
    $optical->setTitle('OPTICAS');
    $optical->fromArray([
        ['MARCA', 'SUCURSAL', 'DIRECCION', 'TELEFONO'],
        ['OLAB', 'CENTER PLAZA', 'Av Optica 1', '5522222222'],
        ['OLAB', 'NUEVA SUCURSAL', 'Av Optica Nueva', '5533333333'],
    ]);

    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

it('aborts when dry-run is not provided', function () {
    $this->artisan('laboratory:stores-gda-import', ['path' => 'missing.xlsx'])
        ->expectsOutput('Apply mode is not enabled in this phase.')
        ->assertFailed();
});

it('persists only audit rows during dry-run', function () {
    LaboratoryStore::query()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'CENTER PLAZA',
        'state' => 'Ciudad de México',
        'address' => 'Av Test, 123, Centro, Iztacalco, CDMX, 1010',
        'weekly_hours' => '7-15',
        'saturday_hours' => '8-12',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example/center',
    ]);

    LaboratoryStore::query()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'ANZURES TULYEHUALCO',
        'state' => 'Ciudad de México',
        'address' => 'Otra, 9, Anzures, Miguel Hidalgo, CDMX, 11590',
        'weekly_hours' => '7-15',
        'saturday_hours' => '8-12',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example/anzures',
    ]);

    $before = gdaBusinessTableCounts();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => gdaFixturePath(),
        '--dry-run' => true,
        '--brand' => 'olab',
    ])
        ->expectsOutputToContain('DIRECTORIO')
        ->expectsOutputToContain('HISTORIA CLINICA')
        ->expectsOutputToContain('OPTICAS')
        ->expectsOutputToContain('Processed including auxiliary sheets')
        ->assertSuccessful();

    $totals = LaboratoryStoreImportRun::query()->firstOrFail()->totals;

    expect(gdaBusinessTableCounts())->toBe($before)
        ->and($totals['directory_rows'])->toBe(3)
        ->and($totals['clinical_history_rows'])->toBe(2)
        ->and($totals['optical_rows'])->toBe(2)
        ->and(LaboratoryStoreImportRun::query()->count())->toBe(1)
        ->and(LaboratoryStoreImportRow::query()->count())->toBe(7)
        ->and(LaboratoryStoreImportRow::query()->where('classification', LaboratoryStoreImportRow::CLASSIFICATION_MATCHED)->count())->toBe(5)
        ->and(LaboratoryStoreImportRow::query()->where('classification', LaboratoryStoreImportRow::CLASSIFICATION_AMBIGUOUS)->count())->toBe(1)
        ->and(LaboratoryStoreImportRow::query()->where('classification', LaboratoryStoreImportRow::CLASSIFICATION_NEW)->count())->toBe(1);
});

it('keeps store classification separate from invalid field validation', function () {
    LaboratoryStore::query()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'ALTAVISTA',
        'state' => 'Ciudad de México',
        'address' => 'Av Altavista 1, Alvaro Obregon, 01000',
        'weekly_hours' => '7-15',
        'saturday_hours' => '8-12',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example/altavista',
    ]);

    $path = gdaFixturePath();
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $spreadsheet->getSheetByName('DIRECTORIO');
    $sheet->setCellValue('B5', 'ALTAVISTA');
    $sheet->setCellValue('K5', '999');
    (new Xlsx($spreadsheet))->save($path);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--dry-run' => true,
        '--brand' => 'olab',
    ])->assertSuccessful();

    $row = LaboratoryStoreImportRow::query()
        ->where('excel_sheet', 'DIRECTORIO')
        ->where('source_name', 'ALTAVISTA')
        ->firstOrFail();

    expect($row->classification)->toBe(LaboratoryStoreImportRow::CLASSIFICATION_MATCHED)
        ->and($row->validation_status)->toBe(LaboratoryStoreImportRow::VALIDATION_INVALID_FIELDS)
        ->and($row->invalid_fields)->toContain('latitude');
});

it('uses CP and municipality evidence to mark rename-like rows as ambiguous', function () {
    LaboratoryStore::query()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'CENTER PLAZA VIGA',
        'state' => 'Ciudad de México',
        'address' => 'Calzada de la Viga 1174, Iztacalco, 08720',
        'weekly_hours' => '7-15',
        'saturday_hours' => '8-12',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example/center-viga',
    ]);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => gdaFixturePath(),
        '--dry-run' => true,
        '--brand' => 'olab',
    ])->assertSuccessful();

    $row = LaboratoryStoreImportRow::query()
        ->where('excel_sheet', 'DIRECTORIO')
        ->where('source_name', 'CENTER PLAZA')
        ->firstOrFail();

    expect($row->classification)->toBe(LaboratoryStoreImportRow::CLASSIFICATION_AMBIGUOUS)
        ->and($row->action)->toBe(LaboratoryStoreImportRow::ACTION_MANUAL_REVIEW)
        ->and($row->evidence['strength'])->toBe('MEDIUM');
});

it('registers a manual MATCH_EXISTING resolution', function () {
    $store = LaboratoryStore::query()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'ANZURES TULYEHUALCO',
        'state' => 'Ciudad de México',
        'address' => 'Otra, 9, Anzures, Miguel Hidalgo, CDMX, 11590',
        'weekly_hours' => '7-15',
        'saturday_hours' => '8-12',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example/anzures',
    ]);

    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'ANZURES',
        '--decision' => 'match',
        '--db-id' => (string) $store->id,
        '--notes' => 'Confirmado por operaciones',
    ])->assertSuccessful();

    $resolution = LaboratoryStoreImportResolution::query()->firstOrFail();

    expect($resolution->decision)->toBe(LaboratoryStoreImportResolution::DECISION_MATCH_EXISTING)
        ->and($resolution->matched_store_id)->toBe($store->id)
        ->and($resolution->normalized_source_name)->toBe('anzures')
        ->and($resolution->notes)->toBe('Confirmado por operaciones');
});

it('registers manual CREATE_NEW and SKIP resolutions', function () {
    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'NUEVA SUCURSAL',
        '--decision' => 'create',
    ])->assertSuccessful();

    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'CENTER PLAZA',
        '--decision' => 'skip',
    ])->assertSuccessful();

    expect(LaboratoryStoreImportResolution::query()->where('decision', LaboratoryStoreImportResolution::DECISION_CREATE_NEW)->count())->toBe(1)
        ->and(LaboratoryStoreImportResolution::query()->where('decision', LaboratoryStoreImportResolution::DECISION_SKIP)->count())->toBe(1)
        ->and(LaboratoryStoreImportResolution::query()->whereNotNull('matched_store_id')->count())->toBe(0);
});

it('rejects manual match to a wrong-brand store', function () {
    $store = LaboratoryStore::query()->create([
        'brand' => LaboratoryBrand::AZTECA->value,
        'name' => 'ANZURES',
        'state' => 'Ciudad de México',
        'address' => 'Otra, 9, Anzures, Miguel Hidalgo, CDMX, 11590',
        'weekly_hours' => '7-15',
        'saturday_hours' => '8-12',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example/azteca-anzures',
    ]);

    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'ANZURES',
        '--decision' => 'match',
        '--db-id' => (string) $store->id,
    ])->assertFailed();

    expect(LaboratoryStoreImportResolution::query()->count())->toBe(0);
});

it('rejects manual match to a nonexistent store', function () {
    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'ANZURES',
        '--decision' => 'match',
        '--db-id' => '999999',
    ])->assertFailed();

    expect(LaboratoryStoreImportResolution::query()->count())->toBe(0);
});

it('lets a manual match override an automatic ambiguous row during dry-run', function () {
    $store = LaboratoryStore::query()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'CENTER PLAZA VIGA',
        'state' => 'Ciudad de México',
        'address' => 'Calzada de la Viga 1174, Iztacalco, 01010',
        'weekly_hours' => '7-15',
        'saturday_hours' => '8-12',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example/center-viga',
    ]);

    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'CENTER PLAZA',
        '--decision' => 'match',
        '--db-id' => (string) $store->id,
    ])->assertSuccessful();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => gdaFixturePath(),
        '--dry-run' => true,
        '--brand' => 'olab',
        '--verbose' => true,
    ])->assertSuccessful();

    $row = LaboratoryStoreImportRow::query()
        ->where('excel_sheet', 'DIRECTORIO')
        ->where('source_name', 'CENTER PLAZA')
        ->firstOrFail();

    expect($row->auto_classification)->toBe(LaboratoryStoreImportRow::CLASSIFICATION_AMBIGUOUS)
        ->and($row->resolution_source)->toBe(LaboratoryStoreImportRow::RESOLUTION_SOURCE_MANUAL)
        ->and($row->resolution_decision)->toBe(LaboratoryStoreImportResolution::DECISION_MATCH_EXISTING)
        ->and($row->classification)->toBe(LaboratoryStoreImportRow::CLASSIFICATION_MATCHED)
        ->and($row->matched_store_id)->toBe($store->id);
});

it('lets a manual create override an automatic new row during dry-run', function () {
    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'NUEVA SUCURSAL',
        '--decision' => 'create',
    ])->assertSuccessful();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => gdaFixturePath(),
        '--dry-run' => true,
        '--brand' => 'olab',
    ])->assertSuccessful();

    $row = LaboratoryStoreImportRow::query()
        ->where('excel_sheet', 'DIRECTORIO')
        ->where('source_name', 'NUEVA SUCURSAL')
        ->firstOrFail();

    expect($row->auto_classification)->toBe(LaboratoryStoreImportRow::CLASSIFICATION_NEW)
        ->and($row->resolution_source)->toBe(LaboratoryStoreImportRow::RESOLUTION_SOURCE_MANUAL)
        ->and($row->resolution_decision)->toBe(LaboratoryStoreImportResolution::DECISION_CREATE_NEW)
        ->and($row->classification)->toBe(LaboratoryStoreImportRow::CLASSIFICATION_NEW)
        ->and($row->action)->toBe(LaboratoryStoreImportRow::ACTION_CREATE);
});

it('lets a manual skip block a row during dry-run', function () {
    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'NUEVA SUCURSAL',
        '--decision' => 'skip',
    ])->assertSuccessful();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => gdaFixturePath(),
        '--dry-run' => true,
        '--brand' => 'olab',
    ])->assertSuccessful();

    $row = LaboratoryStoreImportRow::query()
        ->where('excel_sheet', 'DIRECTORIO')
        ->where('source_name', 'NUEVA SUCURSAL')
        ->firstOrFail();

    expect($row->resolution_source)->toBe(LaboratoryStoreImportRow::RESOLUTION_SOURCE_MANUAL)
        ->and($row->resolution_decision)->toBe(LaboratoryStoreImportResolution::DECISION_SKIP)
        ->and($row->action)->toBe(LaboratoryStoreImportRow::ACTION_SKIP);
});

it('keeps manual resolution imports idempotent and business tables unchanged', function () {
    $beforeBusinessTables = gdaBusinessTableCounts();

    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'NUEVA SUCURSAL',
        '--decision' => 'create',
    ])->assertSuccessful();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => gdaFixturePath(),
        '--dry-run' => true,
        '--brand' => 'olab',
    ])->assertSuccessful();

    $first = LaboratoryStoreImportRow::query()
        ->where('excel_sheet', 'DIRECTORIO')
        ->where('source_name', 'NUEVA SUCURSAL')
        ->latest('id')
        ->firstOrFail();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => gdaFixturePath(),
        '--dry-run' => true,
        '--brand' => 'olab',
    ])->assertSuccessful();

    $second = LaboratoryStoreImportRow::query()
        ->where('excel_sheet', 'DIRECTORIO')
        ->where('source_name', 'NUEVA SUCURSAL')
        ->latest('id')
        ->firstOrFail();

    expect(gdaBusinessTableCounts())->toBe($beforeBusinessTables)
        ->and($second->classification)->toBe($first->classification)
        ->and($second->action)->toBe($first->action)
        ->and($second->manual_resolution_id)->toBe($first->manual_resolution_id);
});

it('preserves manual resolution history when a decision is replaced', function () {
    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'NUEVA SUCURSAL',
        '--decision' => 'create',
    ])->assertSuccessful();

    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'NUEVA SUCURSAL',
        '--decision' => 'skip',
    ])->assertSuccessful();

    expect(LaboratoryStoreImportResolution::query()->count())->toBe(2)
        ->and(LaboratoryStoreImportResolution::query()->current()->count())->toBe(1)
        ->and(LaboratoryStoreImportResolution::query()->whereNotNull('superseded_at')->count())->toBe(1)
        ->and(LaboratoryStoreImportResolution::query()->current()->firstOrFail()->decision)->toBe(LaboratoryStoreImportResolution::DECISION_SKIP);
});

function gdaCreateApplyStores(): array
{
    $center = LaboratoryStore::query()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'CENTER PLAZA',
        'state' => 'Ciudad de M�xico',
        'address' => 'Old Center Address',
        'weekly_hours' => 'old',
        'saturday_hours' => 'old',
        'sunday_hours' => 'old',
        'google_maps_url' => 'https://maps.example/center-real',
        'latitude' => '19.0000000',
        'longitude' => '-99.0000000',
    ]);

    $anzures = LaboratoryStore::query()->create([
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'ANZURES TULYEHUALCO',
        'state' => 'Ciudad de M�xico',
        'address' => 'Otra, 9, Anzures, Miguel Hidalgo, CDMX, 11590',
        'weekly_hours' => '7-15',
        'saturday_hours' => '8-12',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.example/anzures-real',
    ]);

    return [$center, $anzures];
}

function gdaResolvedApplyRun($test): array
{
    [$center, $anzures] = gdaCreateApplyStores();

    $test->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'ANZURES',
        '--decision' => 'match',
        '--db-id' => (string) $anzures->id,
    ])->assertSuccessful();

    $path = gdaFixturePath();
    $hash = hash_file('sha256', $path);

    $test->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--dry-run' => true,
        '--brand' => 'olab',
    ])->assertSuccessful();

    return [$path, $hash, LaboratoryStoreImportRun::query()->latest('id')->firstOrFail(), $center, $anzures];
}

it('blocks apply by default even when the plan is resolved', function () {
    [$path, $hash, $run] = gdaResolvedApplyRun($this);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'olab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'OLAB',
    ])
        ->expectsOutputToContain('Apply mode is disabled')
        ->assertFailed();
});

it('blocks apply when a manual resolution is still missing', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    [, $anzures] = gdaCreateApplyStores();
    $path = gdaFixturePath();
    $hash = hash_file('sha256', $path);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--dry-run' => true,
        '--brand' => 'olab',
    ])->assertSuccessful();

    $run = LaboratoryStoreImportRun::query()->latest('id')->firstOrFail();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'olab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'OLAB',
    ])
        ->expectsOutputToContain('UNRESOLVED_IMPORT_PLAN')
        ->assertFailed();

    expect($anzures->refresh()->name)->toBe('ANZURES TULYEHUALCO');
});

it('blocks apply when the source file hash changed', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    [$path, $hash, $run] = gdaResolvedApplyRun($this);

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $spreadsheet->getSheetByName('DIRECTORIO')->setCellValue('D5', 'Changed Street');
    (new Xlsx($spreadsheet))->save($path);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'olab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'OLAB',
    ])
        ->expectsOutputToContain('SOURCE_FILE_CHANGED')
        ->assertFailed();
});

it('blocks apply when a matched DB row changed after dry-run', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    [$path, $hash, $run, $center] = gdaResolvedApplyRun($this);

    $center->update(['name' => 'CENTER PLAZA MUTATED']);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'olab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'OLAB',
    ])
        ->expectsOutputToContain('STALE_IMPORT_PLAN')
        ->assertFailed();
});

it('blocks apply when the confirmation token does not match the requested brand', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    [$path, $hash, $run] = gdaResolvedApplyRun($this);

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

it('applies a resolved fixture transactionally and is idempotent across repeated dry-run/apply cycles', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    [$path, $hash, $run, $center, $anzures] = gdaResolvedApplyRun($this);
    $beforeAnzuresId = $anzures->id;

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'olab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'OLAB',
    ])->assertSuccessful();

    $created = LaboratoryStore::query()->where('name', 'NUEVA SUCURSAL')->firstOrFail();

    expect($center->refresh()->id)->toBe($center->id)
        ->and($center->name)->toBe('CENTER PLAZA')
        ->and($center->address)->toContain('Av Test')
        ->and($center->google_maps_url)->toBe('https://maps.example/center-real')
        ->and($anzures->refresh()->id)->toBe($beforeAnzuresId)
        ->and($anzures->name)->toBe('ANZURES')
        ->and($created->id)->not->toBeNull()
        ->and(LaboratoryStore::query()->where('name', 'NUEVA SUCURSAL')->count())->toBe(1)
        ->and(\Illuminate\Support\Facades\DB::table('laboratory_capabilities')->count())->toBe(29)
        ->and(\Illuminate\Support\Facades\DB::table('laboratory_store_capability')->count())->toBeGreaterThan(0)
        ->and(\Illuminate\Support\Facades\DB::table('laboratory_store_hours')->count())->toBeGreaterThan(0)
        ->and(\Illuminate\Support\Facades\DB::table('laboratory_store_services')->count())->toBe(4)
        ->and(LaboratoryStoreImportRow::query()->whereNotNull('before_snapshot')->count())->toBeGreaterThan(0)
        ->and(LaboratoryStoreImportRow::query()->whereNotNull('after_snapshot')->count())->toBeGreaterThan(0);

    $countsAfterFirst = gdaBusinessTableCounts();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--dry-run' => true,
        '--brand' => 'olab',
    ])->assertSuccessful();

    $secondRun = LaboratoryStoreImportRun::query()->latest('id')->firstOrFail();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'olab',
        '--run-id' => (string) $secondRun->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'OLAB',
    ])->assertSuccessful();

    expect(gdaBusinessTableCounts())->toBe($countsAfterFirst)
        ->and(LaboratoryStore::query()->where('name', 'NUEVA SUCURSAL')->count())->toBe(1)
        ->and(\Illuminate\Support\Facades\DB::table('laboratory_store_services')->count())->toBe(4);
});

it('does not persist invalid coordinates during apply', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    [$center, $anzures] = gdaCreateApplyStores();

    $this->artisan('laboratory:stores-gda-resolve', [
        '--brand' => 'olab',
        '--store' => 'ANZURES',
        '--decision' => 'match',
        '--db-id' => (string) $anzures->id,
    ])->assertSuccessful();

    $path = gdaFixturePath();
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet = $spreadsheet->getSheetByName('DIRECTORIO');
    $sheet->setCellValue('K7', '999999999');
    $sheet->setCellValue('L7', '-999999999999');
    (new Xlsx($spreadsheet))->save($path);
    $hash = hash_file('sha256', $path);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--dry-run' => true,
        '--brand' => 'olab',
    ])->assertSuccessful();

    $run = LaboratoryStoreImportRun::query()->latest('id')->firstOrFail();

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'olab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'OLAB',
    ])->assertSuccessful();

    $created = LaboratoryStore::query()->where('name', 'NUEVA SUCURSAL')->firstOrFail();

    expect($created->latitude)->toBeNull()
        ->and($created->longitude)->toBeNull()
        ->and($center->refresh()->latitude)->toEqual('19.1340470');
});

it('rolls back the full transaction when a fixture apply row fails', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    [$path, $hash, $run, $center] = gdaResolvedApplyRun($this);
    $originalAddress = $center->address;

    $newRow = LaboratoryStoreImportRow::query()
        ->where('run_id', $run->id)
        ->where('source_name', 'NUEVA SUCURSAL')
        ->firstOrFail();
    $planned = $newRow->diff;
    $planned['planned']['name'] = null;
    $newRow->update(['source_name' => null, 'planned_payload' => ['name' => null], 'diff' => $planned]);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'olab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'OLAB',
    ])->assertFailed();

    expect($center->refresh()->address)->toBe($originalAddress)
        ->and(LaboratoryStore::query()->where('name', 'NUEVA SUCURSAL')->count())->toBe(0)
        ->and(LaboratoryStoreImportRow::query()->whereNotNull('apply_status')->count())->toBe(0);
});

it('generates SQL preview without business writes and ends with rollback', function () {
    [$path] = gdaResolvedApplyRun($this);
    $before = gdaBusinessTableCounts();
    $export = 'imports/olab-preview-fixture.sql';

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--dry-run' => true,
        '--brand' => 'olab',
        '--export-sql' => $export,
    ])->assertSuccessful();

    $contents = file_get_contents(storage_path('app/'.$export));

    expect(gdaBusinessTableCounts())->toBe($before)
        ->and($contents)->toContain('START TRANSACTION;')
        ->and($contents)->toContain('Generated preview only')
        ->and(trim($contents))->toEndWith('ROLLBACK;');
});

it('exports a scoped brand backup without business writes', function () {
    LaboratoryStore::factory()->create([
        'brand' => 'olab',
        'name' => 'OLAB BACKUP FIXTURE',
        'state' => 'CDMX',
        'address' => 'Backup fixture address',
        'weekly_hours' => 'Lunes a Viernes de 8:00 a 17:00',
        'saturday_hours' => 'Sabado de 8:00 a 12:00',
        'sunday_hours' => 'Cerrado',
        'google_maps_url' => 'https://maps.google.com/?q=OLAB%20BACKUP%20FIXTURE',
    ]);

    [$path] = gdaResolvedApplyRun($this);
    $before = gdaBusinessTableCounts();
    $export = 'imports/olab-backup-fixture.json';

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--brand' => 'olab',
        '--export-backup' => $export,
    ])->assertSuccessful();

    $contents = json_decode(file_get_contents(storage_path('app/'.$export)), true);

    expect(gdaBusinessTableCounts())->toBe($before)
        ->and($contents['brand'])->toBe('olab')
        ->and($contents['stores'])->toHaveCount(3);
});
it('generates rollback SQL preview from an applied fixture run', function () {
    config(['laboratory-stores.gda_import.apply_enabled' => true]);
    [$path, $hash, $run] = gdaResolvedApplyRun($this);

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--apply' => true,
        '--brand' => 'olab',
        '--run-id' => (string) $run->id,
        '--confirm-hash' => $hash,
        '--confirm-apply' => 'OLAB',
    ])->assertSuccessful();

    $export = 'imports/olab-rollback-fixture.sql';

    $this->artisan('laboratory:stores-gda-import', [
        'path' => $path,
        '--run-id' => (string) $run->id,
        '--brand' => 'olab',
        '--export-rollback' => $export,
    ])->assertSuccessful();

    $contents = file_get_contents(storage_path('app/'.$export));

    expect($contents)->toContain('Generated rollback preview only')
        ->and($contents)->toContain('DELETE FROM laboratory_stores')
        ->and(trim($contents))->toEndWith('ROLLBACK;');
});
