<?php

use App\Models\OdessaReconciliationItem;
use App\Services\Odessa\Reconciliation\OdessaCollaboratorSourceRow;
use App\Services\Odessa\Reconciliation\OdessaReconciliationAdminPayload;
use App\Services\Odessa\Reconciliation\OdessaReconciliationReport;
use App\Services\Odessa\Reconciliation\OdessaReconciliationResult;
use App\Services\Odessa\Reconciliation\OdessaReconciliationSummary;
use Carbon\CarbonImmutable;

it('builds the admin payload without changing reconciliation semantics', function () {
    $source = new OdessaCollaboratorSourceRow(
        sourceSheet: 'ODESSA',
        sourceRow: 59,
        companyExternalId: '5000',
        employeeNumber: '1214',
        firstName: 'Oswaldo Isaac',
        paternalLastname: 'Santiago',
        maternalLastname: 'Ramírez',
        birthDate: CarbonImmutable::parse('1990-08-11'),
        email: 'osantiago@odessa.com.mx',
        odessaId: '69285',
    );
    $source->canonicalId = 'G0058-ODESSA-59';
    $source->duplicateGroupId = 'G0058';
    $source->canonicalRow = 59;

    $result = new OdessaReconciliationResult($source);
    $result->existsInFamedic = true;
    $result->activeMatchFound = true;
    $result->matchType = 'MATCH_CONFIRMED_ODESSA_ID';
    $result->matchConfidence = 'alta';
    $result->status = 'REGISTRO_COMPLETO';
    $result->identityStatus = 'CONFIRMED';
    $result->accountStatus = 'ODESSA_ACTIVE';
    $result->membershipStatus = 'ACTIVE';
    $result->murguiaStatus = 'FAMEDIC_Y_MURGUIA';
    $result->existsInMurguiaReport = true;
    $result->dataQualityFlags = ['EMAIL_DIFFERENT'];
    $result->evidence = ['Excel ID ODESSA 69285 = DB ID ODESSA 69285'];
    $result->reviewNotes = ['El correo del Excel es diferente del correo actual en FAMEDIC.'];
    $result->matched = (object) [
        'user_id' => 770,
        'customer_id' => 869,
        'odessa_account_id' => 1321,
        'email' => 'mrodriguez@odessa.com.mx',
        'email_normalized' => 'mrodriguez@odessa.com.mx',
        'odessa_identifier' => '69285',
        'name' => 'Oswaldo Isaac',
        'paternal_lastname' => 'Santiago',
        'maternal_lastname' => 'Ramírez',
        'birth_date' => '1990-08-11',
        'company_internal_id' => 2,
        'company_external_id_db' => '5000',
        'partner_identifier' => '1214',
        'customerable_type' => 'App\\Models\\OdessaAfiliateAccount',
        'medical_attention_identifier' => '7429424519',
        'subscription_id' => 67,
        'subscription_type' => 'institutional',
        'subscription_start_date' => '2025-03-18 19:55:16',
        'subscription_end_date' => '2026-12-31 23:59:59',
        'subscription_active' => true,
        'subscription_status' => 'ACTIVE',
        'subscription_count' => 1,
        'synced_with_murguia_at' => '2025-08-21 17:27:15',
        'customer_deleted_at' => null,
        'odessa_deleted_at' => null,
        'subscription_deleted_at' => null,
        'has_only_deleted_subscription' => false,
    ];

    $summary = OdessaReconciliationSummary::fromResults([$result]);
    $report = new OdessaReconciliationReport('/tmp/source.xlsx', [$result], $summary, '/tmp/export.xlsx');

    $payload = app(OdessaReconciliationAdminPayload::class)->fromReport(
        $report,
        'source.xlsx',
        'murguia.xlsx',
        'reconciliation/out.xlsx',
    );

    expect($payload['summary']['unique_collaborators'])->toBe(1)
        ->and($payload['summary']['confirmed'])->toBe(1)
        ->and($payload['summary']['email_different'])->toBe(1)
        ->and($payload['summary']['famedic_and_murguia'])->toBe(1)
        ->and($payload['rows'][0]['match']['label'])->toBe('ID ODESSA')
        ->and($payload['rows'][0]['membership']['status_label'])->toBe('Activa')
        ->and($payload['rows'][0]['dimensions']['flags'])->toBe(['EMAIL_DIFFERENT'])
        ->and($payload['rows'][0]['famedic']['customer_url'])->toBe('/admin/customers/869');
});

it('marks only operationally risky snapshots as requiring manual review', function () {
    expect(OdessaReconciliationItem::requiresManualReviewFromSnapshot([
        'match_type' => 'MATCH_CONFIRMED_ODESSA_ID',
        'final_status' => 'REGISTRO_COMPLETO',
        'data_quality_flags' => 'EMAIL_DIFFERENT',
    ]))->toBeFalse()
        ->and(OdessaReconciliationItem::requiresManualReviewFromSnapshot([
            'match_type' => 'NO_MATCH',
            'final_status' => 'NO_REGISTRADO_EN_FAMEDIC',
            'data_quality_flags' => '',
        ]))->toBeTrue()
        ->and(OdessaReconciliationItem::requiresManualReviewFromSnapshot([
            'match_type' => 'MATCH_PROBABLE_IDENTITY',
            'final_status' => 'REVISAR_MANUALMENTE',
            'data_quality_flags' => '',
        ]))->toBeTrue()
        ->and(OdessaReconciliationItem::requiresManualReviewFromSnapshot([
            'match_type' => 'MATCH_CONFIRMED_ODESSA_ID',
            'final_status' => 'REGISTRO_COMPLETO',
            'data_quality_flags' => 'DISCREPANCIA_IDENTITY',
        ]))->toBeTrue();
});

it('exposes operational filter counts, blocked reasons and noCredito state', function () {
    $altaBlocked = adminPayloadResult([
        'employeeNumber' => '1365',
        'sourceAction' => 'ALTA',
        'sourceActionColor' => 'E2F0D9',
        'email' => 'alta@example.test',
    ], null);
    $altaBlocked->status = 'NO_REGISTRADO_EN_FAMEDIC';
    $altaBlocked->auditReason = 'EMAIL_NOT_FOUND';

    $bajaReady = adminPayloadResult([
        'employeeNumber' => '1174',
        'sourceAction' => 'BAJA',
        'sourceActionColor' => 'FFC000',
        'email' => 'baja@example.test',
    ], [
        'subscription_status' => 'ACTIVE',
        'medical_attention_identifier' => '7429424519',
    ]);
    $bajaReady->murguiaStatus = 'FAMEDIC_Y_MURGUIA';
    $bajaReady->existsInMurguiaReport = true;

    $summary = OdessaReconciliationSummary::fromResults([$altaBlocked, $bajaReady]);
    $report = new OdessaReconciliationReport('/tmp/source.xlsx', [$altaBlocked, $bajaReady], $summary, '/tmp/export.xlsx');

    $payload = app(OdessaReconciliationAdminPayload::class)->fromReport($report, 'source.xlsx', 'murguia.xlsx', 'out.xlsx');

    expect($payload['filter_counts']['altas'])->toBe(1)
        ->and($payload['filter_counts']['bajas'])->toBe(1)
        ->and($payload['filter_counts']['blocked'])->toBe(1)
        ->and($payload['filter_counts']['without_number'])->toBe(1)
        ->and($payload['operation_views']['altas']['blocked'])->toBe(1)
        ->and($payload['operation_views']['bajas']['ready'])->toBe(1)
        ->and($payload['rows'][0]['membership']['identifier'])->toBeNull()
        ->and($payload['rows'][0]['dimensions']['blocked_reasons'])->toContain('NO_FAMEDIC_MATCH', 'NO_CUSTOMER', 'NO_CREDIT_NUMBER');
});

function adminPayloadResult(array $sourceOverrides = [], ?array $matchedOverrides = []): OdessaReconciliationResult
{
    $source = new OdessaCollaboratorSourceRow(
        sourceSheet: 'ODESSA',
        sourceRow: random_int(10, 300),
        companyExternalId: '5000',
        employeeNumber: $sourceOverrides['employeeNumber'] ?? '1214',
        firstName: 'Persona',
        paternalLastname: 'Operativa',
        maternalLastname: 'Test',
        birthDate: CarbonImmutable::parse('1990-01-01'),
        email: $sourceOverrides['email'] ?? 'persona@example.test',
        odessaId: $sourceOverrides['odessaId'] ?? null,
        sourceAction: $sourceOverrides['sourceAction'] ?? 'NONE',
        sourceActionColor: $sourceOverrides['sourceActionColor'] ?? null,
    );
    $source->canonicalId = 'G'.random_int(1000, 9999);

    $result = new OdessaReconciliationResult($source);
    $result->matchType = $matchedOverrides === null ? 'NO_MATCH' : 'MATCH_CONFIRMED_COMPANY_PARTNER';
    $result->matchConfidence = $matchedOverrides === null ? 'none' : 'alta';
    $result->status = $matchedOverrides === null ? 'NO_REGISTRADO_EN_FAMEDIC' : 'REGISTRO_COMPLETO';
    $result->identityStatus = $matchedOverrides === null ? 'NO_MATCH' : 'CONFIRMED';
    $result->accountStatus = $matchedOverrides === null ? 'NO_ACCOUNT' : 'ODESSA_ACTIVE';
    $result->membershipStatus = $matchedOverrides['subscription_status'] ?? 'ACTIVE';
    $result->murguiaStatus = $matchedOverrides === null ? null : 'FAMEDIC_NO_MURGUIA';
    $result->existsInFamedic = $matchedOverrides !== null;

    if ($matchedOverrides !== null) {
        $result->matched = (object) array_merge([
            'user_id' => 1,
            'customer_id' => 2,
            'odessa_account_id' => 3,
            'email' => $source->email,
            'email_normalized' => $source->email,
            'odessa_identifier' => null,
            'name' => 'Persona',
            'paternal_lastname' => 'Operativa',
            'maternal_lastname' => 'Test',
            'birth_date' => '1990-01-01',
            'company_internal_id' => 4,
            'company_external_id_db' => '5000',
            'partner_identifier' => $source->employeeNumber,
            'customerable_type' => 'App\\Models\\OdessaAfiliateAccount',
            'medical_attention_identifier' => '1234567890',
            'subscription_id' => 5,
            'subscription_type' => 'institutional',
            'subscription_start_date' => now()->subDay()->toDateTimeString(),
            'subscription_end_date' => now()->addYear()->toDateTimeString(),
            'subscription_active' => true,
            'subscription_status' => 'ACTIVE',
            'subscription_count' => 1,
            'synced_with_murguia_at' => now()->toDateTimeString(),
            'customer_deleted_at' => null,
            'odessa_deleted_at' => null,
            'subscription_deleted_at' => null,
            'has_only_deleted_subscription' => false,
        ], $matchedOverrides);
    }

    return $result;
}
