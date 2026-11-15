<?php

use App\Models\LaboratoryPackageCommercialApproval;
use App\Models\LaboratoryTestPackage;
use App\Models\LaboratoryTestPackageDescriptor;
use Illuminate\Support\Facades\DB;

/*
 * RC2 B02: aprobación comercial transaccional, auditable y todo-o-nada.
 * Paquete sintético con la misma forma que el piloto 4965 (2 descriptores + 4 estudios).
 */

function b02ApprovalPackage(): array
{
    $packageTest = createOlabTest([
        'name' => 'Paquete sintético aprobación',
        'gda_id' => 'B02AP-PKG-'.bin2hex(random_bytes(3)),
        'public_price_cents' => 155400,
        'famedic_price_cents' => 67900,
    ]);
    $package = LaboratoryTestPackage::query()->create(['package_test_id' => $packageTest->id]);
    $descriptors = [];
    foreach ([1, 2] as $order) {
        $descriptors[] = LaboratoryTestPackageDescriptor::query()->create([
            'package_id' => $package->id,
            'description' => "Descriptor sintético {$order}",
            'sort_order' => $order,
            'validation_status' => LaboratoryTestPackageDescriptor::STATUS_PENDING,
        ]);
    }
    $studies = [];
    foreach ([3, 4, 5, 6] as $order) {
        $study = createOlabTest(['gda_id' => 'B02AP-ST-'.bin2hex(random_bytes(3))]);
        $package->components()->attach($study->id, ['sort_order' => $order]);
        $studies[] = $study;
    }

    return [$package, $packageTest, $descriptors, $studies];
}

function b02ApprovalFile(array $data): string
{
    $path = tempnam(sys_get_temp_dir(), 'b02ap');
    file_put_contents($path, json_encode($data));

    return $path;
}

function b02ApprovalPayload($packageTest, array $descriptors, array $studies, array $override = []): array
{
    $items = [];
    foreach ($descriptors as $d) {
        $items[] = ['type' => 'descriptor', 'descriptor_id' => $d->id, 'reference' => "COM-B02-SINT/D{$d->id}"];
    }
    foreach ($studies as $s) {
        $items[] = ['type' => 'study', 'component_test_id' => $s->id, 'reference' => "COM-B02-SINT/S{$s->id}"];
    }

    return array_merge([
        'package_test_id' => $packageTest->id,
        'approver' => 'Aprobador Sintético (FAMEDIC Comercial)',
        'approved_at' => now()->subHour()->toIso8601String(),
        'reference' => 'COM-B02-SINT-001',
        'items' => $items,
    ], $override);
}

function b02Statuses($package): array
{
    return array_merge(
        DB::table('laboratory_test_package_components')->where('package_id', $package->id)->pluck('validation_status')->all(),
        DB::table('laboratory_test_package_descriptors')->where('package_id', $package->id)->pluck('validation_status')->all(),
    );
}

test('B02 approval is dry-run by default and writes nothing', function () {
    [$package, $packageTest, $descriptors, $studies] = b02ApprovalPackage();
    $file = b02ApprovalFile(b02ApprovalPayload($packageTest, $descriptors, $studies));

    $this->artisan('akubica:b02-commercial-approval', ['--file' => $file, '--operator' => 'qa-operator'])
        ->expectsOutputToContain('"result":"dry_run"')
        ->assertSuccessful();

    expect(array_unique(b02Statuses($package)))->toBe([LaboratoryTestPackageDescriptor::STATUS_PENDING]);
    expect(LaboratoryPackageCommercialApproval::query()->count())->toBe(0);
});

test('B02 apply requires the exact confirmation phrase', function () {
    [$package, $packageTest, $descriptors, $studies] = b02ApprovalPackage();
    $file = b02ApprovalFile(b02ApprovalPayload($packageTest, $descriptors, $studies));

    $this->artisan('akubica:b02-commercial-approval', ['--file' => $file, '--operator' => 'qa-operator', '--apply' => true, '--confirm' => 'si'])
        ->expectsOutputToContain('B02_CONFIRMATION_REQUIRED')
        ->assertFailed();

    expect(array_unique(b02Statuses($package)))->toBe([LaboratoryTestPackageDescriptor::STATUS_PENDING]);
});

test('B02 apply validates all six rows atomically and writes one audit record with before and after', function () {
    [$package, $packageTest, $descriptors, $studies] = b02ApprovalPackage();
    $file = b02ApprovalFile(b02ApprovalPayload($packageTest, $descriptors, $studies));

    $this->artisan('akubica:b02-commercial-approval', ['--file' => $file, '--operator' => 'qa-operator', '--apply' => true, '--confirm' => 'B02-COMMERCIAL-APPROVAL'])
        ->expectsOutputToContain('"result":"applied"')
        ->assertSuccessful();

    expect(b02Statuses($package))->toHaveCount(6);
    expect(array_unique(b02Statuses($package)))->toBe([LaboratoryTestPackageDescriptor::STATUS_VALIDATED]);

    $audit = LaboratoryPackageCommercialApproval::query()->sole();
    expect($audit->approver)->toBe('Aprobador Sintético (FAMEDIC Comercial)')
        ->and($audit->approval_reference)->toBe('COM-B02-SINT-001')
        ->and($audit->operator)->toBe('qa-operator')
        ->and($audit->input_sha256)->toBe(hash_file('sha256', $file))
        ->and(collect($audit->before_state)->pluck('status')->unique()->all())->toBe([LaboratoryTestPackageDescriptor::STATUS_PENDING])
        ->and(collect($audit->after_state)->pluck('status')->unique()->all())->toBe([LaboratoryTestPackageDescriptor::STATUS_VALIDATED])
        ->and(count($audit->after_state))->toBe(6);

    $this->getJson("/api/v1/catalog/packages/{$packageTest->id}")
        ->assertOk()
        ->assertJsonPath('data.composition_status', 'validated')
        ->assertJsonPath('data.pricing.famedic_price_cents', 67900);

    // Segunda ejecución idéntica: no-op, sin nueva auditoría.
    $this->artisan('akubica:b02-commercial-approval', ['--file' => $file, '--operator' => 'qa-operator', '--apply' => true, '--confirm' => 'B02-COMMERCIAL-APPROVAL'])
        ->expectsOutputToContain('"result":"noop"')
        ->assertSuccessful();
    expect(LaboratoryPackageCommercialApproval::query()->count())->toBe(1);
});

test('B02 rejects partial coverage, extra items, invalid references, missing approver and future dates without writing', function (callable $mutate, string $code) {
    [$package, $packageTest, $descriptors, $studies] = b02ApprovalPackage();
    $payload = $mutate(b02ApprovalPayload($packageTest, $descriptors, $studies));
    $file = b02ApprovalFile($payload);

    $this->artisan('akubica:b02-commercial-approval', ['--file' => $file, '--operator' => 'qa-operator', '--apply' => true, '--confirm' => 'B02-COMMERCIAL-APPROVAL'])
        ->expectsOutputToContain($code)
        ->assertFailed();

    expect(array_unique(b02Statuses($package)))->toBe([LaboratoryTestPackageDescriptor::STATUS_PENDING]);
    expect(LaboratoryPackageCommercialApproval::query()->count())->toBe(0);
})->with([
    'falta un estudio' => [fn (array $p) => array_merge($p, ['items' => array_slice($p['items'], 0, 5)]), 'B02_ITEMS_MUST_COVER_FULL_COMPOSITION'],
    'elemento ajeno' => [fn (array $p) => array_merge($p, ['items' => [...$p['items'], ['type' => 'study', 'component_test_id' => 999999, 'reference' => 'COM-B02-SINT/X']]]), 'B02_ITEMS_MUST_COVER_FULL_COMPOSITION'],
    'referencia vacía en un elemento' => [function (array $p) { $p['items'][2]['reference'] = ''; return $p; }, 'B02_REFERENCE_INVALID'],
    'referencia general inválida' => [fn (array $p) => array_merge($p, ['reference' => 'aprobado por whatsapp']), 'B02_REFERENCE_INVALID'],
    'sin aprobador' => [fn (array $p) => array_merge($p, ['approver' => '']), 'B02_APPROVER_REQUIRED'],
    'fecha futura' => [fn (array $p) => array_merge($p, ['approved_at' => now()->addDay()->toIso8601String()]), 'B02_APPROVED_AT_INVALID'],
    'duplicado' => [fn (array $p) => array_merge($p, ['items' => [...$p['items'], $p['items'][0]]]), 'B02_ITEM_DUPLICATED'],
]);

test('B02 rejects when any row is no longer pending (all-or-nothing)', function () {
    [$package, $packageTest, $descriptors, $studies] = b02ApprovalPackage();
    $descriptors[1]->update(['validation_status' => LaboratoryTestPackageDescriptor::STATUS_REJECTED]);
    $file = b02ApprovalFile(b02ApprovalPayload($packageTest, $descriptors, $studies));

    $this->artisan('akubica:b02-commercial-approval', ['--file' => $file, '--operator' => 'qa-operator', '--apply' => true, '--confirm' => 'B02-COMMERCIAL-APPROVAL'])
        ->expectsOutputToContain('B02_ROW_NOT_PENDING')
        ->assertFailed();

    expect(DB::table('laboratory_test_package_components')->where('package_id', $package->id)->pluck('validation_status')->unique()->all())
        ->toBe([LaboratoryTestPackageDescriptor::STATUS_PENDING]);
    expect(LaboratoryPackageCommercialApproval::query()->count())->toBe(0);
});

test('B02 rolls back every row when a failure happens inside the transaction', function () {
    [$package, $packageTest, $descriptors, $studies] = b02ApprovalPackage();
    $file = b02ApprovalFile(b02ApprovalPayload($packageTest, $descriptors, $studies));

    // Fuerza un fallo al insertar la auditoría (última operación de la transacción).
    LaboratoryPackageCommercialApproval::creating(function () {
        throw new RuntimeException('B02_SIMULATED_AUDIT_FAILURE');
    });

    $this->artisan('akubica:b02-commercial-approval', ['--file' => $file, '--operator' => 'qa-operator', '--apply' => true, '--confirm' => 'B02-COMMERCIAL-APPROVAL'])
        ->expectsOutputToContain('B02_SIMULATED_AUDIT_FAILURE')
        ->assertFailed();

    expect(array_unique(b02Statuses($package)))->toBe([LaboratoryTestPackageDescriptor::STATUS_PENDING]);
    expect(DB::table('laboratory_package_commercial_approvals')->count())->toBe(0);

    LaboratoryPackageCommercialApproval::flushEventListeners();
});

test('B02 rejects missing operator and missing file', function () {
    $this->artisan('akubica:b02-commercial-approval', ['--file' => '/nonexistent.json', '--operator' => 'qa-operator'])
        ->expectsOutputToContain('B02_INPUT_FILE_REQUIRED')->assertFailed();
    $this->artisan('akubica:b02-commercial-approval', ['--file' => '/nonexistent.json'])
        ->expectsOutputToContain('B02_OPERATOR_REQUIRED')->assertFailed();
});
