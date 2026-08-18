<?php

use App\Exports\OdessaCollaboratorReconciliationExport;
use App\Models\OdessaAfiliateAccount;
use App\Models\RegularAccount;
use App\Services\Odessa\Reconciliation\OdessaCollaboratorSourceRow;
use App\Services\Odessa\Reconciliation\OdessaReconciliationResult;
use App\Services\Odessa\Reconciliation\OdessaReconciliationSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function odessaExportResult(array $sourceOverrides = [], array $matchedOverrides = []): OdessaReconciliationResult
{
    $source = new OdessaCollaboratorSourceRow(
        sourceSheet: 'ODESSA',
        sourceRow: $sourceOverrides['sourceRow'] ?? 2,
        companyExternalId: $sourceOverrides['companyExternalId'] ?? '5000',
        employeeNumber: $sourceOverrides['employeeNumber'] ?? '1214',
        firstName: $sourceOverrides['firstName'] ?? 'Persona',
        paternalLastname: $sourceOverrides['paternalLastname'] ?? 'Operativa',
        maternalLastname: $sourceOverrides['maternalLastname'] ?? 'Test',
        birthDate: CarbonImmutable::parse($sourceOverrides['birthDate'] ?? '1990-01-01'),
        email: $sourceOverrides['email'] ?? 'persona@example.test',
        odessaId: $sourceOverrides['odessaId'] ?? '69285',
        membershipIdentifier: $sourceOverrides['membershipIdentifier'] ?? null,
        sourceAction: $sourceOverrides['sourceAction'] ?? 'NONE',
        sourceActionColor: $sourceOverrides['sourceActionColor'] ?? null,
    );

    $result = new OdessaReconciliationResult($source);
    $result->existsInFamedic = true;
    $result->activeMatchFound = true;
    $result->matchType = 'MATCH_CONFIRMED_ODESSA_ID';
    $result->matchConfidence = 'alta';
    $result->status = 'REGISTRO_COMPLETO';
    $result->identityStatus = 'CONFIRMED';
    $result->accountStatus = 'ODESSA_ACTIVE';
    $result->membershipStatus = $matchedOverrides['subscription_status'] ?? 'ACTIVE';
    $result->evidence = ['Excel ID ODESSA 69285 = DB ID ODESSA 69285'];
    $result->matched = (object) array_merge([
        'user_id' => 770,
        'customer_id' => 869,
        'odessa_account_id' => 1321,
        'email' => 'persona-db@example.test',
        'email_normalized' => 'persona-db@example.test',
        'odessa_identifier' => '69285',
        'name' => 'Persona',
        'paternal_lastname' => 'Operativa',
        'maternal_lastname' => 'Test',
        'birth_date' => '1990-01-01',
        'company_internal_id' => 2,
        'company_external_id_db' => '5000',
        'partner_identifier' => '1214',
        'customerable_type' => OdessaAfiliateAccount::class,
        'account_type_label' => 'Odessa',
        'medical_attention_identifier' => '7429424519',
        'subscription_id' => 67,
        'subscription_type' => 'institutional',
        'subscription_type_label' => 'Institucional Odessa',
        'subscription_start_date' => '2026-01-01 00:00:00',
        'subscription_end_date' => '2026-12-31 00:00:00',
        'subscription_active' => true,
        'subscription_status' => 'ACTIVE',
        'subscription_status_label' => 'Activa',
        'subscription_count' => 1,
        'synced_with_murguia_at' => null,
        'customer_deleted_at' => null,
        'odessa_deleted_at' => null,
        'subscription_deleted_at' => null,
        'has_only_deleted_subscription' => false,
    ], $matchedOverrides);

    return $result;
}

it('exports exactly the current fourteen operational sheets with enriched headers', function () {
    $results = [
        odessaExportResult(['sourceAction' => 'ALTA', 'sourceActionColor' => '00B050', 'employeeNumber' => '1001']),
        odessaExportResult(['sourceAction' => 'BAJA', 'sourceActionColor' => 'FFC000', 'employeeNumber' => '1002']),
    ];
    $export = new OdessaCollaboratorReconciliationExport($results, OdessaReconciliationSummary::fromResults($results));
    $sheets = $export->sheets();

    expect(array_map(fn ($sheet) => $sheet->title(), $sheets))->toBe([
        'Resumen',
        'Detalle',
        'Todos',
        'Altas',
        'Bajas',
        'Coincidencias exactas',
        'Posibles coincidencias',
        'Sin coincidencia',
        'Errores datos',
        'Acciones disponibles',
        'Acciones bloqueadas',
        'Acciones ejecutadas',
        'Murguía comparación',
        'Diccionario columnas',
    ]);

    foreach (array_slice($sheets, 1, -1) as $sheet) {
        expect($sheet->headings())->toContain(
            'Tipo de cuenta',
            'Tipo de membresía',
            'Estado membresía',
            'Membresía activa',
            'Fecha inicio membresía',
            'Fecha de vencimiento',
        );
    }

    expect($sheets[13]->array())->toContain(
        ['Tipo de cuenta', 'Tipo de cuenta local del customer FAMEDIC: Odessa, Regular, Familiar o Certificado / convenio.'],
    );
});

it('keeps enriched values consistent between Todos and Altas/Bajas sheets', function () {
    $alta = odessaExportResult(['sourceAction' => 'ALTA', 'sourceActionColor' => '00B050', 'employeeNumber' => '1001']);
    $baja = odessaExportResult(['sourceAction' => 'BAJA', 'sourceActionColor' => 'FFC000', 'employeeNumber' => '1002']);
    $export = new OdessaCollaboratorReconciliationExport([$alta, $baja], OdessaReconciliationSummary::fromResults([$alta, $baja]));

    [, , $todos, $altas, $bajas] = $export->sheets();
    $headers = $todos->headings();
    $employeeColumn = array_search('# empleado', $headers, true);
    $enrichedColumns = array_map(fn ($heading) => array_search($heading, $headers, true), [
        'Tipo de cuenta',
        'Tipo de membresía',
        'Estado membresía',
        'Membresía activa',
        'Fecha inicio membresía',
        'Fecha de vencimiento',
    ]);

    $todosRowsByEmployee = collect($todos->array())->keyBy(fn ($row) => $row[$employeeColumn]);

    foreach ([$altas->array()[0], $bajas->array()[0]] as $segregatedRow) {
        $sourceRow = $todosRowsByEmployee[$segregatedRow[$employeeColumn]];
        foreach ($enrichedColumns as $column) {
            expect($segregatedRow[$column])->toBe($sourceRow[$column]);
        }
    }
});

it('exports friendly membership labels for Odessa active and Regular without membership cases', function () {
    $odessa = odessaExportResult(['employeeNumber' => '1001']);
    $regular = odessaExportResult(['employeeNumber' => '1002'], [
        'customerable_type' => RegularAccount::class,
        'account_type_label' => 'Regular',
        'medical_attention_identifier' => null,
        'subscription_id' => null,
        'subscription_type' => null,
        'subscription_type_label' => 'Ninguna',
        'subscription_start_date' => null,
        'subscription_end_date' => null,
        'subscription_active' => false,
        'subscription_status' => 'MISSING',
        'subscription_status_label' => 'Sin membresía',
    ]);
    $export = new OdessaCollaboratorReconciliationExport([$odessa, $regular], OdessaReconciliationSummary::fromResults([$odessa, $regular]));

    $todos = $export->sheets()[2];
    $headers = $todos->headings();
    $index = fn (string $heading) => array_search($heading, $headers, true);
    $rows = collect($todos->array())->keyBy(fn ($row) => $row[$index('# empleado')]);

    expect($rows['1001'][$index('Tipo de cuenta')])->toBe('Odessa')
        ->and($rows['1001'][$index('Tipo de membresía')])->toBe('Institucional Odessa')
        ->and($rows['1001'][$index('Estado membresía')])->toBe('Activa')
        ->and($rows['1001'][$index('Membresía activa')])->toBe('Sí')
        ->and($rows['1002'][$index('Tipo de cuenta')])->toBe('Regular')
        ->and($rows['1002'][$index('Tipo de membresía')])->toBe('Ninguna')
        ->and($rows['1002'][$index('Estado membresía')])->toBe('Sin membresía')
        ->and($rows['1002'][$index('Membresía activa')])->toBe('No');
});

it('does not query while generating segregated export sheets from enriched result rows', function () {
    $results = [
        odessaExportResult(['sourceAction' => 'ALTA']),
        odessaExportResult(['sourceAction' => 'BAJA']),
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();

    $export = new OdessaCollaboratorReconciliationExport($results, OdessaReconciliationSummary::fromResults($results));
    foreach ($export->sheets() as $sheet) {
        $sheet->array();
    }

    expect(DB::getQueryLog())->toBe([]);
});
