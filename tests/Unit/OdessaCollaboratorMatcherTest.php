<?php

use App\Models\OdessaAfiliateAccount;
use App\Services\Odessa\Reconciliation\OdessaCollaboratorExcelParser;
use App\Services\Odessa\Reconciliation\OdessaCollaboratorMatcher;
use App\Services\Odessa\Reconciliation\OdessaCollaboratorSourceRow;
use App\Services\Odessa\Reconciliation\OdessaReconciliationDbIndex;
use App\Services\Odessa\Reconciliation\OdessaReconciliationMatchTypes;
use App\Services\Odessa\Reconciliation\OdessaReconciliationNormalizer;
use App\Services\Odessa\Reconciliation\OdessaReconciliationStatuses;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function odessaUnitSource(array $overrides = []): OdessaCollaboratorSourceRow
{
    $defaults = [
        'sourceSheet' => 'ODESSA',
        'sourceRow' => 2,
        'companyExternalId' => '5000',
        'employeeNumber' => '1214',
        'firstName' => 'Oswaldo Isaac',
        'paternalLastname' => 'Santiago',
        'maternalLastname' => 'Ramírez',
        'birthDate' => CarbonImmutable::parse('1990-08-11'),
        'email' => 'osantiago@odessa.com.mx',
        'odessaId' => '69285',
        'membershipIdentifier' => null,
    ];

    return new OdessaCollaboratorSourceRow(...array_merge($defaults, $overrides));
}

function odessaUnitCandidate(array $overrides = []): stdClass
{
    $row = (object) array_merge([
        'user_id' => 770,
        'name' => 'Oswaldo Isaac',
        'paternal_lastname' => 'Santiago',
        'maternal_lastname' => 'Ramírez',
        'email' => 'mrodriguez@odessa.com.mx',
        'birth_date' => '1990-08-11',
        'customer_id' => 869,
        'customerable_type' => OdessaAfiliateAccount::class,
        'customerable_id' => 1321,
        'medical_attention_identifier' => '7429424519',
        'medical_attention_subscription_expires_at' => now()->addYear()->toDateTimeString(),
        'customer_deleted_at' => null,
        'odessa_account_id' => 1321,
        'odessa_identifier' => '69285',
        'partner_identifier' => '1214',
        'company_internal_id' => 2,
        'odessa_deleted_at' => null,
        'company_external_id_db' => '5000',
        'company_name' => null,
        'company_deleted_at' => null,
        'subscription_id' => 67,
        'subscription_type' => 'institutional',
        'subscription_start_date' => now()->subDay()->toDateTimeString(),
        'subscription_end_date' => now()->addYear()->toDateTimeString(),
        'synced_with_murguia_at' => now()->toDateTimeString(),
        'subscription_deleted_at' => null,
        'subscription_active' => true,
        'subscription_status' => 'ACTIVE',
        'subscription_count' => 1,
        'non_deleted_subscription_count' => 1,
        'deleted_subscription_count' => 0,
        'has_only_deleted_subscription' => false,
        'is_trashed' => false,
    ], $overrides);

    $row->email_normalized = OdessaReconciliationNormalizer::email($row->email);
    $row->identity_key = OdessaReconciliationNormalizer::identityKey(
        $row->name,
        $row->paternal_lastname,
        $row->maternal_lastname,
        $row->birth_date,
    );
    $row->loose_identity_key = OdessaReconciliationNormalizer::comparableName(
        $row->name,
        $row->paternal_lastname,
        $row->maternal_lastname,
    );

    return $row;
}

function odessaUnitIndex(array $candidates): OdessaReconciliationDbIndex
{
    $index = new OdessaReconciliationDbIndex;

    foreach ($candidates as $candidate) {
        $active = ! $candidate->is_trashed;

        if ($candidate->odessa_identifier) {
            $bucket = $active ? 'activeByOdessaId' : 'trashedByOdessaId';
            $index->{$bucket}[$candidate->odessa_identifier][] = $candidate;
        }

        if ($candidate->company_external_id_db && $candidate->partner_identifier) {
            $bucket = $active ? 'activeByCompanyPartner' : 'trashedByCompanyPartner';
            $index->{$bucket}[$candidate->company_external_id_db.'|'.$candidate->partner_identifier][] = $candidate;
        }

        if ($active && $candidate->medical_attention_identifier) {
            $index->activeByMembership[$candidate->medical_attention_identifier][] = $candidate;
        }

        if ($active && $candidate->email_normalized) {
            $index->activeByEmail[$candidate->email_normalized][] = $candidate;
        } elseif (! $active && $candidate->email_normalized) {
            $index->trashedByEmail[$candidate->email_normalized][] = $candidate;
        }

        if ($active && $candidate->identity_key !== '|||') {
            $index->activeByIdentity[$candidate->identity_key][] = $candidate;
        } elseif (! $active && $candidate->identity_key !== '|||') {
            $index->trashedByIdentity[$candidate->identity_key][] = $candidate;
        }

        if ($active && $candidate->loose_identity_key !== '') {
            $index->activeByLooseIdentity[$candidate->loose_identity_key][] = $candidate;
        } elseif (! $active && $candidate->loose_identity_key !== '') {
            $index->trashedByLooseIdentity[$candidate->loose_identity_key][] = $candidate;
        }
    }

    return $index;
}

function odessaUnitMatch(OdessaCollaboratorSourceRow $source, array $candidates)
{
    return (new OdessaCollaboratorMatcher(odessaUnitIndex($candidates)))->match($source);
}

it('matches by ODESSA ID even when email is different', function () {
    $result = odessaUnitMatch(odessaUnitSource(), [odessaUnitCandidate()]);

    expect($result->existsInFamedic)->toBeTrue()
        ->and($result->matchType)->toBe(OdessaReconciliationMatchTypes::CONFIRMED_ODESSA_ID)
        ->and($result->status)->toBe(OdessaReconciliationStatuses::COMPLETE)
        ->and($result->dataQualityFlags)->toContain('EMAIL_DIFFERENT')
        ->and($result->matched->email)->toBe('mrodriguez@odessa.com.mx');
});

it('matches by company external identifier plus partner when ODESSA ID is unavailable', function () {
    $result = odessaUnitMatch(odessaUnitSource(['odessaId' => null]), [
        odessaUnitCandidate(['odessa_identifier' => '77777']),
    ]);

    expect($result->matchType)->toBe(OdessaReconciliationMatchTypes::CONFIRMED_COMPANY_PARTNER)
        ->and($result->existsInFamedic)->toBeTrue();
});

it('does not confirm a match by partner alone', function () {
    $result = odessaUnitMatch(odessaUnitSource([
        'odessaId' => null,
        'companyExternalId' => null,
        'email' => null,
        'firstName' => 'No',
        'paternalLastname' => 'Existe',
        'maternalLastname' => 'SoloSocio',
        'birthDate' => CarbonImmutable::parse('2000-01-01'),
    ]), [
        odessaUnitCandidate(['company_external_id_db' => '5000', 'partner_identifier' => '1214']),
        odessaUnitCandidate(['user_id' => 771, 'email' => 'other@example.test', 'company_external_id_db' => '6000', 'partner_identifier' => '1214', 'odessa_identifier' => '99999']),
    ]);

    expect($result->existsInFamedic)->toBeFalse()
        ->and($result->status)->toBe(OdessaReconciliationStatuses::NOT_FOUND);
});

it('falls back to email when no ODESSA references are available', function () {
    $candidate = odessaUnitCandidate([
        'email' => 'correo@odessa.com.mx',
        'customerable_type' => 'App\\Models\\RegularAccount',
        'odessa_account_id' => null,
        'odessa_identifier' => null,
        'partner_identifier' => null,
        'company_external_id_db' => null,
    ]);

    $result = odessaUnitMatch(odessaUnitSource([
        'odessaId' => null,
        'companyExternalId' => null,
        'employeeNumber' => null,
        'email' => 'correo@odessa.com.mx',
    ]), [$candidate]);

    expect($result->matchType)->toBe(OdessaReconciliationMatchTypes::CONFIRMED_EMAIL)
        ->and($result->status)->toBe(OdessaReconciliationStatuses::USER_WITHOUT_ODESSA);
});

it('marks identity matches as probable manual review', function () {
    $candidate = odessaUnitCandidate([
        'name' => 'María Luisa',
        'paternal_lastname' => 'Pérez',
        'maternal_lastname' => 'Gómez',
        'birth_date' => '1991-02-03',
        'email' => 'db@example.test',
        'odessa_identifier' => null,
        'partner_identifier' => null,
        'company_external_id_db' => null,
    ]);
    $candidate->identity_key = OdessaReconciliationNormalizer::identityKey('María Luisa', 'Pérez', 'Gómez', '1991-02-03');

    $result = odessaUnitMatch(odessaUnitSource([
        'odessaId' => null,
        'companyExternalId' => null,
        'employeeNumber' => null,
        'email' => null,
        'firstName' => 'Maria Luisa',
        'paternalLastname' => 'Perez',
        'maternalLastname' => 'Gomez',
        'birthDate' => CarbonImmutable::parse('1991-02-03'),
    ]), [$candidate]);

    expect($result->matchType)->toBe(OdessaReconciliationMatchTypes::PROBABLE_IDENTITY)
        ->and($result->status)->toBe(OdessaReconciliationStatuses::MANUAL_REVIEW);
});

it('marks a collaborator as not found when no signals match', function () {
    $result = odessaUnitMatch(odessaUnitSource([
        'odessaId' => 'nope',
        'companyExternalId' => '9999',
        'employeeNumber' => '8888',
        'email' => 'missing@example.test',
        'firstName' => 'Missing',
        'paternalLastname' => 'Person',
        'maternalLastname' => 'Test',
        'birthDate' => CarbonImmutable::parse('1970-01-01'),
    ]), []);

    expect($result->existsInFamedic)->toBeFalse()
        ->and($result->status)->toBe(OdessaReconciliationStatuses::NOT_FOUND);
});

it('does not silently treat soft deleted ODESSA accounts as active', function () {
    $result = odessaUnitMatch(odessaUnitSource(), [
        odessaUnitCandidate(['is_trashed' => true, 'odessa_deleted_at' => now()->toDateTimeString()]),
    ]);

    expect($result->matchType)->toBe(OdessaReconciliationMatchTypes::DELETED)
        ->and($result->status)->toBe(OdessaReconciliationStatuses::DELETED_RECORD);
});

it('resolves the Oswaldo proof of concept', function () {
    $result = odessaUnitMatch(odessaUnitSource(), [odessaUnitCandidate()]);

    expect($result->matched->user_id)->toBe(770)
        ->and($result->matched->customer_id)->toBe(869)
        ->and($result->matched->odessa_identifier)->toBe('69285')
        ->and($result->matched->partner_identifier)->toBe('1214')
        ->and($result->matched->company_external_id_db)->toBe('5000')
        ->and($result->matched->medical_attention_identifier)->toBe('7429424519')
        ->and($result->matchType)->toBe(OdessaReconciliationMatchTypes::CONFIRMED_ODESSA_ID)
        ->and($result->status)->toBe(OdessaReconciliationStatuses::COMPLETE)
        ->and($result->dataQualityFlags)->toContain('EMAIL_DIFFERENT');
});

it('classifies missing identifier with subscription separately', function () {
    $result = odessaUnitMatch(odessaUnitSource(), [
        odessaUnitCandidate(['medical_attention_identifier' => null]),
    ]);

    expect($result->status)->toBe(OdessaReconciliationStatuses::AFFILIATE_WITHOUT_MEMBERSHIP)
        ->and($result->dataQualityFlags)->toContain('SUBSCRIPTION_WITHOUT_IDENTIFIER');
});

it('classifies identifier without subscription separately', function () {
    $result = odessaUnitMatch(odessaUnitSource(), [
        odessaUnitCandidate([
            'subscription_id' => null,
            'subscription_status' => 'MISSING',
            'subscription_active' => false,
        ]),
    ]);

    expect($result->status)->toBe(OdessaReconciliationStatuses::AFFILIATE_WITHOUT_MEMBERSHIP)
        ->and($result->dataQualityFlags)->toContain('IDENTIFIER_WITHOUT_SUBSCRIPTION');
});

it('classifies expired, future, and deleted-only subscriptions as not active memberships', function (array $overrides) {
    $result = odessaUnitMatch(odessaUnitSource(), [odessaUnitCandidate($overrides)]);

    expect($result->status)->toBe(OdessaReconciliationStatuses::EXPIRED_MEMBERSHIP);
})->with([
    [['subscription_status' => 'EXPIRED', 'subscription_active' => false]],
    [['subscription_status' => 'FUTURE', 'subscription_active' => false]],
]);

it('classifies deleted-only subscriptions as affiliate without usable membership', function () {
    $result = odessaUnitMatch(odessaUnitSource(), [
        odessaUnitCandidate([
            'subscription_id' => null,
            'subscription_status' => 'DELETED_ONLY',
            'subscription_active' => false,
            'has_only_deleted_subscription' => true,
        ]),
    ]);

    expect($result->status)->toBe(OdessaReconciliationStatuses::AFFILIATE_WITHOUT_MEMBERSHIP);
});

it('exposes enriched FAMEDIC account and membership labels for export', function (array $overrides, string $statusLabel, string $activeLabel) {
    $result = odessaUnitMatch(odessaUnitSource(), [odessaUnitCandidate($overrides)]);
    $row = $result->toArray();

    expect($row['account_type_label'])->toBe('Odessa')
        ->and($row['subscription_type_label'])->toBe('Institucional Odessa')
        ->and($row['subscription_status_label'])->toBe($statusLabel)
        ->and($row['membership_active_label'])->toBe($activeLabel);
})->with([
    'active' => [['subscription_status' => 'ACTIVE', 'subscription_active' => true], 'Activa', 'Sí'],
    'expired' => [['subscription_status' => 'EXPIRED', 'subscription_active' => false], 'Vencida', 'No'],
    'future' => [['subscription_status' => 'FUTURE', 'subscription_active' => false], 'Futura', 'No'],
    'missing' => [['subscription_id' => null, 'subscription_status' => 'MISSING', 'subscription_active' => false, 'subscription_start_date' => null, 'subscription_end_date' => null], 'Sin membresía', 'No'],
    'deleted_only' => [['subscription_id' => null, 'subscription_status' => 'DELETED_ONLY', 'subscription_active' => false, 'subscription_start_date' => null, 'subscription_end_date' => null, 'has_only_deleted_subscription' => true], 'Eliminada/Histórica', 'No'],
]);

it('controls enriched fields when no FAMEDIC match exists', function () {
    $result = odessaUnitMatch(odessaUnitSource([
        'odessaId' => 'nope',
        'companyExternalId' => '9999',
        'employeeNumber' => '8888',
        'email' => 'missing@example.test',
    ]), []);
    $row = $result->toArray();

    expect($row['account_type_label'])->toBeNull()
        ->and($row['subscription_type_label'])->toBeNull()
        ->and($row['subscription_status_label'])->toBe('Sin registro')
        ->and($row['membership_active_label'])->toBe('No')
        ->and($row['subscription_start_date'])->toBeNull()
        ->and($row['subscription_end_date'])->toBeNull();
});

it('flags identity discrepancy when ODESSA ID matches a different person', function () {
    $result = odessaUnitMatch(odessaUnitSource(), [
        odessaUnitCandidate([
            'name' => 'Persona Distinta',
            'paternal_lastname' => 'Otra',
            'maternal_lastname' => 'Identidad',
            'birth_date' => '1970-01-01',
        ]),
    ]);

    expect($result->matchType)->toBe(OdessaReconciliationMatchTypes::CONFIRMED_ODESSA_ID)
        ->and($result->dataQualityFlags)->toContain('DISCREPANCIA_IDENTITY');
});

it('marks duplicate rows using the configured priority key', function () {
    $rows = [
        odessaUnitSource(['sourceRow' => 2, 'email' => 'a@example.test', 'odessaId' => '111']),
        odessaUnitSource(['sourceRow' => 3, 'email' => 'b@example.test', 'odessaId' => '111']),
    ];

    $method = new ReflectionMethod(OdessaCollaboratorExcelParser::class, 'markDuplicates');
    $method->setAccessible(true);
    $method->invoke(new OdessaCollaboratorExcelParser, $rows);

    expect($rows[0]->isDuplicate)->toBeFalse()
        ->and($rows[1]->isDuplicate)->toBeTrue()
        ->and($rows[1]->duplicateReason)->toBe('DUPLICATE_ODESSA_ID')
        ->and($rows[0]->duplicateCount)->toBe(2);
});

it('does not merge same email when ODESSA IDs are different because ODESSA ID has priority', function () {
    $rows = [
        odessaUnitSource(['sourceRow' => 2, 'email' => 'same@example.test', 'odessaId' => '111']),
        odessaUnitSource(['sourceRow' => 3, 'email' => 'same@example.test', 'odessaId' => '222']),
    ];

    $method = new ReflectionMethod(OdessaCollaboratorExcelParser::class, 'markDuplicates');
    $method->setAccessible(true);
    $method->invoke(new OdessaCollaboratorExcelParser, $rows);

    expect($rows[0]->duplicateCount)->toBe(1)
        ->and($rows[1]->duplicateCount)->toBe(1);
});

it('detects source actions from Excel row colors without mutating collaborator data', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['# empleado', 'Apellido Paterno', 'Apellido Materno', 'Nombre', 'Correos Electronicos'],
        ['1001', 'Alta', 'Persona', 'Andrea', 'alta@example.test'],
        ['1002', 'Baja', 'Persona', 'Bruno', 'baja@example.test'],
        ['1003', 'Normal', 'Persona', 'Carla', 'normal@example.test'],
    ]);
    $sheet->getStyle('A2:E2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('00B050');
    $sheet->getStyle('A3:E3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFF00');

    $path = tempnam(sys_get_temp_dir(), 'odessa-source-actions-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $rows = (new OdessaCollaboratorExcelParser)->parse($path);
    @unlink($path);

    expect(array_map(fn (OdessaCollaboratorSourceRow $row) => $row->sourceAction, $rows))
        ->toBe(['ALTA', 'BAJA', 'NONE'])
        ->and($rows[0]->sourceActionColor)->toBe('00B050')
        ->and($rows[1]->sourceActionColor)->toBe('FFFF00')
        ->and($rows[2]->sourceActionColor)->toBeNull();
});

it('parses an enriched exported workbook without treating new columns as source data', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('ODESSA');
    $sheet->fromArray([
        [
            '# de empresa',
            '# empleado',
            'Apellido Paterno',
            'Apellido Materno',
            'Nombre',
            'Fecha de nacimiento',
            'Correos Electronicos',
            'ID-ODESSA',
            'Otro correo / cuenta FAMEDIC',
            'No. membresía / noCredito',
            'Tipo de cuenta',
            'Tipo de membresía',
            'Estado membresía',
            'Membresía activa',
            'Fecha inicio membresía',
            'Fecha de vencimiento',
        ],
        ['5000', '1001', 'Alta', 'Persona', 'Andrea', '1990-01-01', 'alta@example.test', '111', 'alta-db@example.test', '9001', 'Odessa', 'Institucional', 'Activa', 'Sí', '2026-01-01', '2026-12-31'],
        ['5000', '1002', 'Baja', 'Persona', 'Bruno', '1990-01-02', 'baja@example.test', '222', 'baja-db@example.test', '9002', 'Odessa', 'Institucional', 'Vencida', 'No', '2025-01-01', '2025-12-31'],
    ]);
    $sheet->getStyle('A2:P2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('00B050');
    $sheet->getStyle('A3:P3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC000');

    $path = tempnam(sys_get_temp_dir(), 'odessa-enriched-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $rows = (new OdessaCollaboratorExcelParser)->parse($path);
    @unlink($path);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->sourceAction)->toBe('ALTA')
        ->and($rows[1]->sourceAction)->toBe('BAJA')
        ->and($rows[0]->employeeNumber)->toBe('1001')
        ->and($rows[0]->companyExternalId)->toBe('5000')
        ->and($rows[0]->email)->toBe('alta@example.test')
        ->and($rows[0]->odessaId)->toBe('111')
        ->and($rows[0]->membershipIdentifier)->toBe('9001');
});
