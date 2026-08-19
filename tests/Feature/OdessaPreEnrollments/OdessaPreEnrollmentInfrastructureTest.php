<?php

use App\Actions\Customers\GenerateUniqueMedicalAttentionIdAction;
use App\Actions\MedicalAttention\CheckStatusAction;
use App\Actions\MedicalAttention\SyncSubscriptionToMurguiaAction;
use App\Actions\MedicalAttention\UpdateStatusAction;
use App\Actions\Odessa\GeneratePreEnrollmentMedicalAttentionIdAction;
use App\Enums\MedicalSubscriptionType;
use App\Exports\OdessaPreEnrollmentsExport;
use App\Http\Controllers\Admin\MurguiaMonitorController;
use App\Models\Administrator;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use App\Models\MurguiaSyncLog;
use App\Models\OdessaPreEnrollment;
use App\Models\Permission;
use App\Models\RegularAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Murguia\MurguiaInsuredExcelRowProcessor;
use App\Services\Odessa\PreEnrollment\OdessaPreEnrollmentPreviewService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['famedic.odessa_pre_enrollments.enabled' => true]);
});

function odessaPreEnrollmentAdmin(array $permissions = ['odessa-pre-enrollments.view', 'odessa-pre-enrollments.manage', 'odessa-pre-enrollments.actions.generate-credit']): User
{
    $role = Role::firstOrCreate(['name' => 'PreEnrollment Admin', 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    $user = User::factory()->withAdministrator()->create();
    $user->administrator->assignRole($role);

    return $user->fresh('administrator.roles.permissions');
}

function odessaPreEnrollmentWorkbook(array $rows, int $extraSheets = 0, int $extraColumns = 0): string
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Sin Registro');
    $headers = [
        '# de empresa',
        '# empleado',
        'Apellido Paterno',
        'Apellido Materno',
        'Nombre',
        'Fecha de nacimiento',
        'Correos Electronicos',
        'ID-ODESSA',
        'accion',
        'estatus revision',
    ];

    for ($i = 0; $i < $extraColumns; $i++) {
        $headers[] = "Extra {$i}";
    }

    $sheet->fromArray($headers, null, 'A1');

    foreach ($rows as $index => $row) {
        $values = [
            $row['company'] ?? '5000',
            $row['employee'] ?? (string) (1200 + $index),
            $row['paternal'] ?? 'Prueba',
            $row['maternal'] ?? 'Segura',
            $row['name'] ?? 'Persona',
            $row['birth_date'] ?? '1990-01-01',
            $row['email'] ?? "persona{$index}@odessa.test",
            $row['odessa'] ?? null,
            $row['action'] ?? 'ALTA',
            'NO_REGISTRADO_EN_FAMEDIC',
        ];

        for ($i = 0; $i < $extraColumns; $i++) {
            $values[] = "extra {$i}";
        }

        $sheet->fromArray($values, null, 'A'.($index + 2));
        if (isset($row['formula_email'])) {
            $sheet->setCellValue('G'.($index + 2), $row['formula_email']);
        }
    }

    for ($i = 0; $i < $extraSheets; $i++) {
        $spreadsheet->createSheet()->setTitle("Extra {$i}");
    }

    $path = tempnam(sys_get_temp_dir(), 'odessa-pre-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function odessaReservationMigration(): object
{
    return include database_path('migrations/2026_08_19_120050_create_medical_attention_identifier_reservations_table.php');
}

function odessaHttpResponse(int $status, array $body): HttpClientResponse
{
    return new HttpClientResponse(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
}

function expectMurguiaLogsToBeSanitized(): void
{
    MurguiaSyncLog::query()->get()->each(function (MurguiaSyncLog $log): void {
        expect($log->email)->toBeNull()
            ->and($log->medical_attention_identifier)->toBeNull()
            ->and($log->request_payload)->toBeNull()
            ->and(json_encode($log->response_payload))->not->toContain('noCredito')
            ->and($log->message)->not->toContain('Fila')
            ->and($log->message)->not->toContain('@');
    });
}

it('keeps active company employee uniqueness while allowing archived history', function () {
    OdessaPreEnrollment::factory()->create([
        'company_external_identifier' => '5000',
        'employee_identifier' => '1201',
        'status' => OdessaPreEnrollment::STATUS_READY,
    ]);

    expect(fn () => OdessaPreEnrollment::factory()->create([
        'company_external_identifier' => '5000',
        'employee_identifier' => '1201',
        'status' => OdessaPreEnrollment::STATUS_PENDING,
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    OdessaPreEnrollment::factory()->create([
        'company_external_identifier' => '5000',
        'employee_identifier' => '1201',
        'status' => OdessaPreEnrollment::STATUS_ARCHIVED,
    ]);

    expect(OdessaPreEnrollment::count())->toBe(2);
});

it('aborts reservation backfill on cross duplicates without exposing identifiers', function () {
    Schema::dropIfExists('medical_attention_identifier_reservations');
    Customer::factory()->for(User::factory(), 'user')->for(RegularAccount::factory(), 'customerable')->create([
        'medical_attention_identifier' => '1234567890',
    ]);
    OdessaPreEnrollment::factory()->create([
        'medical_attention_identifier' => '1234567890',
    ]);
    $migration = odessaReservationMigration();

    try {
        $migration->up();
        $this->fail('Expected reservation backfill to abort.');
    } catch (\RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('cruzados=1')
            ->not->toContain('1234567890');
    }

    expect(Schema::hasTable('medical_attention_identifier_reservations'))->toBeFalse();
});

it('aborts reservation backfill on customer duplicates without exposing identifiers', function () {
    Schema::dropIfExists('medical_attention_identifier_reservations');
    Schema::table('customers', function ($table) {
        $table->dropUnique('customers_medical_attention_identifier_unique');
    });

    Customer::factory()->for(User::factory(), 'user')->for(RegularAccount::factory(), 'customerable')->create([
        'medical_attention_identifier' => '1234567890',
    ]);
    Customer::factory()->for(User::factory(), 'user')->for(RegularAccount::factory(), 'customerable')->create([
        'medical_attention_identifier' => '1234567890',
    ]);

    try {
        odessaReservationMigration()->up();
        $this->fail('Expected reservation backfill to abort.');
    } catch (\RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('customers=1')
            ->not->toContain('1234567890');
    }

    expect(Schema::hasTable('medical_attention_identifier_reservations'))->toBeFalse();
});

it('aborts reservation backfill on pre enrollment duplicates without exposing identifiers', function () {
    Schema::dropIfExists('medical_attention_identifier_reservations');
    Schema::table('odessa_pre_enrollments', function ($table) {
        $table->dropUnique('ope_medical_identifier_unique');
    });

    OdessaPreEnrollment::factory()->create(['medical_attention_identifier' => '2234567890']);
    OdessaPreEnrollment::factory()->create(['medical_attention_identifier' => '2234567890']);

    try {
        odessaReservationMigration()->up();
        $this->fail('Expected reservation backfill to abort.');
    } catch (\RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('pre_enrollments=1')
            ->not->toContain('2234567890');
    }

    expect(Schema::hasTable('medical_attention_identifier_reservations'))->toBeFalse();
});

it('treats absent customer source table as empty during reservation backfill', function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('medical_attention_identifier_reservations');
    Schema::dropIfExists('customers');

    odessaReservationMigration()->up();

    expect(Schema::hasTable('medical_attention_identifier_reservations'))->toBeTrue()
        ->and(DB::table('medical_attention_identifier_reservations')->count())->toBe(0);
});

it('treats absent pre enrollment source table as empty during reservation backfill', function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('medical_attention_identifier_reservations');
    Schema::dropIfExists('odessa_pre_enrollments');

    Customer::factory()->for(User::factory(), 'user')->for(RegularAccount::factory(), 'customerable')->create([
        'medical_attention_identifier' => '3234567890',
    ]);

    odessaReservationMigration()->up();

    expect(Schema::hasTable('medical_attention_identifier_reservations'))->toBeTrue()
        ->and(DB::table('medical_attention_identifier_reservations')->where('identifier', '3234567890')->exists())->toBeTrue();
});

it('generates ten digit credit numbers without customer or pre enrollment collisions', function () {
    Customer::factory()->for(User::factory(), 'user')->for(RegularAccount::factory(), 'customerable')->create([
        'medical_attention_identifier' => '1234567890',
    ]);
    OdessaPreEnrollment::factory()->create(['medical_attention_identifier' => '2234567890']);

    $code = app(GenerateUniqueMedicalAttentionIdAction::class)();

    expect((string) $code)->toHaveLength(10)
        ->and((int) $code)->toBeGreaterThanOrEqual(1000000000)
        ->and((int) $code)->toBeLessThanOrEqual(9999999999)
        ->and((string) $code)->not->toBe('1234567890')
        ->and((string) $code)->not->toBe('2234567890')
        ->and(DB::table('medical_attention_identifier_reservations')->where('identifier', (string) $code)->exists())->toBeTrue();
});

it('handles forced unique reservation collisions without reassigning ownership', function () {
    $firstCustomer = Customer::factory()->for(User::factory(), 'user')->for(RegularAccount::factory(), 'customerable')->create();
    $secondCustomer = Customer::factory()->for(User::factory(), 'user')->for(RegularAccount::factory(), 'customerable')->create();
    $action = app(GenerateUniqueMedicalAttentionIdAction::class);

    $reserved = $action->reserveExistingIdentifier(
        '1234567890',
        'murguia_provider_assignment',
        Customer::class,
        $firstCustomer->id,
    );
    $sameOwner = $action->reserveExistingIdentifier(
        '1234567890',
        'murguia_provider_assignment_retry',
        Customer::class,
        $firstCustomer->id,
    );
    $otherOwner = $action->reserveExistingIdentifier(
        '1234567890',
        'murguia_provider_assignment',
        Customer::class,
        $secondCustomer->id,
    );

    expect($reserved)->toBeTrue()
        ->and($sameOwner)->toBeTrue()
        ->and($otherOwner)->toBeFalse();
});

it('rejects malformed existing credit numbers before reservation', function (string $identifier) {
    $customer = Customer::factory()->for(User::factory(), 'user')->for(RegularAccount::factory(), 'customerable')->create();

    expect(app(GenerateUniqueMedicalAttentionIdAction::class)->reserveExistingIdentifier(
        $identifier,
        'murguia_provider_assignment',
        Customer::class,
        $customer->id,
    ))->toBeFalse();
})->with(['123456789', '12345678901', '12345abcde']);

it('reserves credit on ready pre enrollment only when flag is enabled and audits the action idempotently', function () {
    config(['famedic.odessa_pre_enrollments.generate_credit_enabled' => true]);
    $admin = odessaPreEnrollmentAdmin();
    $preEnrollment = OdessaPreEnrollment::factory()->create([
        'status' => OdessaPreEnrollment::STATUS_READY,
        'link_status' => OdessaPreEnrollment::LINK_PENDING_ACCOUNT,
        'data_quality_flags' => null,
        'medical_attention_identifier' => null,
    ]);

    $result = app(GeneratePreEnrollmentMedicalAttentionIdAction::class)
        ->execute($preEnrollment, $admin, 'Reserva individual autorizada.');
    $second = app(GeneratePreEnrollmentMedicalAttentionIdAction::class)
        ->execute($preEnrollment->fresh(), $admin, 'Reintento de doble clic.');

    expect($result['ok'])->toBeTrue()
        ->and($preEnrollment->fresh()->medical_attention_identifier)->not->toBeNull()
        ->and($preEnrollment->audits()->where('action_type', 'GENERATE_CREDIT_NUMBER')->count())->toBe(1)
        ->and($second['ok'])->toBeTrue()
        ->and(Customer::count())->toBe(0)
        ->and(User::count())->toBe(1);
});

it('does not return the generated credit number in the route response flash', function () {
    config(['famedic.odessa_pre_enrollments.generate_credit_enabled' => true]);
    $admin = odessaPreEnrollmentAdmin();
    $preEnrollment = OdessaPreEnrollment::factory()->create([
        'status' => OdessaPreEnrollment::STATUS_READY,
        'link_status' => OdessaPreEnrollment::LINK_PENDING_ACCOUNT,
        'data_quality_flags' => null,
        'medical_attention_identifier' => null,
    ]);

    $response = $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.generate-credit', $preEnrollment), [
            'reason' => 'Reserva individual autorizada.',
            'confirmation' => 'CONFIRMAR',
        ]);

    $identifier = $preEnrollment->fresh()->medical_attention_identifier;

    $response->assertRedirect(route('admin.odessa.pre-enrollments.show', $preEnrollment));
    expect($identifier)->not->toBeNull()
        ->and(session('success'))->toBe('noCredito reservado para la preafiliación.')
        ->and(session('success'))->not->toContain($identifier);
});

it('does not expose credit numbers from generation preview endpoints', function () {
    config(['famedic.odessa_pre_enrollments.generate_credit_enabled' => true]);
    $admin = odessaPreEnrollmentAdmin();
    $preEnrollment = OdessaPreEnrollment::factory()->create([
        'status' => OdessaPreEnrollment::STATUS_READY,
        'link_status' => OdessaPreEnrollment::LINK_PENDING_ACCOUNT,
        'data_quality_flags' => null,
        'medical_attention_identifier' => '8234567890',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.generate-credit.preview', $preEnrollment))
        ->assertOk()
        ->assertDontSee('8234567890');
});

it('requires generation permission and the specific generation feature flag', function () {
    $preEnrollment = OdessaPreEnrollment::factory()->create([
        'status' => OdessaPreEnrollment::STATUS_READY,
        'link_status' => OdessaPreEnrollment::LINK_PENDING_ACCOUNT,
        'data_quality_flags' => null,
        'medical_attention_identifier' => null,
    ]);

    $this->actingAs(odessaPreEnrollmentAdmin(['odessa-pre-enrollments.view']))
        ->post(route('admin.odessa.pre-enrollments.generate-credit', $preEnrollment), [
            'reason' => 'Reserva individual autorizada.',
            'confirmation' => 'CONFIRMAR',
        ])
        ->assertForbidden();

    $this->actingAs(odessaPreEnrollmentAdmin(['odessa-pre-enrollments.actions.generate-credit']))
        ->post(route('admin.odessa.pre-enrollments.generate-credit', $preEnrollment), [
            'reason' => 'Reserva individual autorizada.',
            'confirmation' => 'CONFIRMAR',
        ])
        ->assertSessionHasErrors('generate_credit');

    expect($preEnrollment->fresh()->medical_attention_identifier)->toBeNull();
});

it('previews new, already preloaded and other email cases without persisting', function () {
    OdessaPreEnrollment::factory()->create([
        'company_external_identifier' => '5000',
        'employee_identifier' => '1202',
    ]);

    User::factory()->withRegularCustomer()->create([
        'name' => 'Laura',
        'paternal_lastname' => 'Ortega',
        'maternal_lastname' => 'Villa',
        'birth_date' => '1990-01-01',
        'email' => 'laura.personal@example.test',
    ]);

    $path = odessaPreEnrollmentWorkbook([
        ['employee' => '1201', 'name' => 'Nuevo', 'paternal' => 'Seguro', 'maternal' => 'Uno', 'birth_date' => '1991-01-01', 'email' => 'nuevo@odessa.test'],
        ['employee' => '1202', 'name' => 'Precargado', 'paternal' => 'Seguro', 'maternal' => 'Dos', 'birth_date' => '1992-01-01', 'email' => 'precargado@odessa.test'],
        ['employee' => '1203', 'name' => 'Laura', 'paternal' => 'Ortega', 'maternal' => 'Villa', 'email' => 'laura@odessa.test'],
    ]);

    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path);

    expect($preview['meta']['row_count'])->toBe(3)
        ->and(collect($preview['rows'])->pluck('diagnostic_status')->all())->toContain('READY_TO_PRELOAD', 'ALREADY_PRELOADED', 'OTHER_EMAIL')
        ->and(OdessaPreEnrollment::count())->toBe(1);
});

it('returns only sanitized allowlisted preview fields', function () {
    User::factory()->withRegularCustomer()->create([
        'name' => 'Laura',
        'paternal_lastname' => 'Ortega',
        'maternal_lastname' => 'Villa',
        'birth_date' => '1990-01-01',
        'email' => 'laura.personal@example.test',
    ]);

    $path = odessaPreEnrollmentWorkbook([
        ['employee' => '1203', 'name' => 'Laura', 'paternal' => 'Ortega', 'maternal' => 'Villa', 'email' => 'laura@odessa.test'],
    ]);

    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path);
    $row = $preview['rows'][0];

    expect($row)->toHaveKeys([
        'source_row',
        'source_action',
        'diagnostic_status',
        'ready_to_preload',
        'existing_account',
        'existing_email_present',
        'identity_conflict',
        'possible_duplicate',
        'data_quality_flags',
        'notes',
    ])
        ->and($row)->not->toHaveKeys([
            'raw',
            'existing_email',
            'medical_attention_identifier',
            'source_email',
            'birth_date',
            'full_name',
            'possible_candidates',
        ])
        ->and($preview['meta'])->not->toHaveKey('filename')
        ->and($preview['meta'])->toMatchArray([
            'file_received' => true,
            'sheet' => 'Sin Registro',
            'status' => 'analyzed',
        ])
        ->and(json_encode($preview))->not->toContain('laura.personal@example.test')
        ->and(json_encode($preview))->not->toContain('laura@odessa.test');
});

it('does not calculate or expose formula cells from preview workbooks', function () {
    $path = odessaPreEnrollmentWorkbook([
        ['employee' => '1203', 'formula_email' => '=CONCAT("formula","@example.test")'],
    ]);

    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path);

    expect(json_encode($preview))->not->toContain('formula@example.test')
        ->and(json_encode($preview))->not->toContain('CONCAT');
});

it('protects admin routes with pre enrollment permission', function () {
    $user = User::factory()->withAdministrator()->create();

    $this->actingAs($user)
        ->get(route('admin.odessa.pre-enrollments.index'))
        ->assertForbidden();

    $this->actingAs(odessaPreEnrollmentAdmin(['odessa-pre-enrollments.view']))
        ->get(route('admin.odessa.pre-enrollments.index'))
        ->assertOk();
});

it('blocks all pre enrollment admin routes while the module feature flag is off', function () {
    config(['famedic.odessa_pre_enrollments.enabled' => false]);
    $admin = odessaPreEnrollmentAdmin();
    $preEnrollment = OdessaPreEnrollment::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.index'))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.import'))
        ->assertNotFound();

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.import.preview'))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.export'))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.show', $preEnrollment))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.generate-credit.preview', $preEnrollment))
        ->assertNotFound();

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.generate-credit', $preEnrollment))
        ->assertNotFound();
});

it('does not expose sensitive pre enrollment props in index or detail responses', function () {
    config(['famedic.odessa_pre_enrollments.generate_credit_enabled' => true]);
    $admin = odessaPreEnrollmentAdmin(['odessa-pre-enrollments.view']);
    $preEnrollment = OdessaPreEnrollment::factory()->create([
        'first_name' => 'Persona',
        'paternal_last_name' => 'Privada',
        'source_email' => 'persona.privada@example.test',
        'birth_date' => '1990-01-01',
        'medical_attention_identifier' => '4234567890',
        'metadata_json' => ['other_famedic_email' => 'otro@example.test'],
    ]);

    $index = $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.index'))
        ->assertOk();
    $detail = $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.show', $preEnrollment))
        ->assertOk();

    foreach ([$index, $detail] as $response) {
        $response->assertDontSee('Persona')
            ->assertDontSee('Privada')
            ->assertDontSee('persona.privada@example.test')
            ->assertDontSee('otro@example.test')
            ->assertDontSee('1990-01-01')
            ->assertDontSee('4234567890')
            ->assertDontSee('source_snapshot_json')
            ->assertDontSee('metadata_json');
    }
});

it('does not let view-only users infer pre enrollment identity through search filters', function (string $search) {
    $preEnrollment = OdessaPreEnrollment::factory()->create([
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'source_row' => 77,
        'source_action' => OdessaPreEnrollment::ACTION_ALTA,
        'status' => OdessaPreEnrollment::STATUS_READY,
        'link_status' => OdessaPreEnrollment::LINK_PENDING_ACCOUNT,
        'murguia_status' => OdessaPreEnrollment::MURGUIA_NOT_REQUESTED,
        'first_name' => 'Persona',
        'paternal_last_name' => 'Privada',
        'source_email' => 'persona.privada@example.test',
        'odessa_identifier' => 'ODESSA-PRIVADO',
        'medical_attention_identifier' => '4234567890',
    ]);

    $results = OdessaPreEnrollment::query()
        ->filter(['search' => $search], canSearchSensitiveIdentity: false)
        ->pluck('uuid');

    expect($results)->not->toContain($preEnrollment->uuid);
})->with([
    'name' => 'Persona',
    'email' => 'persona.privada@example.test',
    'odessa id' => 'ODESSA-PRIVADO',
    'credit' => '4234567890',
]);

it('lets manage users use administrative identity search without adding sensitive props', function () {
    $preEnrollment = OdessaPreEnrollment::factory()->create([
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'first_name' => 'Persona',
        'paternal_last_name' => 'Autorizada',
        'source_email' => 'persona.autorizada@example.test',
        'odessa_identifier' => 'ODESSA-AUTORIZADO',
        'medical_attention_identifier' => '5234567890',
    ]);

    $results = OdessaPreEnrollment::query()
        ->filter(['search' => 'Autorizada'], canSearchSensitiveIdentity: true)
        ->pluck('uuid');

    expect($results)->toContain($preEnrollment->uuid);
});

it('ignores sensitive credit filters for view-only users', function () {
    $admin = odessaPreEnrollmentAdmin(['odessa-pre-enrollments.view']);
    $withCredit = OdessaPreEnrollment::factory()->create(['medical_attention_identifier' => '6234567890']);
    $withoutCredit = OdessaPreEnrollment::factory()->create(['medical_attention_identifier' => null]);

    $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.index', ['credit' => 'with']))
        ->assertOk()
        ->assertSee($withCredit->uuid)
        ->assertSee($withoutCredit->uuid)
        ->assertDontSee('6234567890');
});

it('does not expose snapshots metadata or audit before after payloads in detail responses', function () {
    $admin = odessaPreEnrollmentAdmin(['odessa-pre-enrollments.view']);
    $preEnrollment = OdessaPreEnrollment::factory()->create([
        'source_snapshot_json' => ['raw' => 'hidden'],
        'metadata_json' => ['internal' => 'hidden', 'other_famedic_email' => 'detected@example.test'],
    ]);
    $preEnrollment->audits()->create([
        'action_type' => 'TEST_ACTION',
        'before_json' => ['raw' => 'hidden'],
        'after_json' => ['raw' => 'hidden', 'has_medical_attention_identifier' => true],
        'reason' => 'Motivo sanitizado',
        'performed_at' => now(),
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.show', $preEnrollment))
        ->assertOk();

    $response->assertDontSee('source_snapshot_json')
        ->assertDontSee('metadata_json')
        ->assertDontSee('before_json')
        ->assertDontSee('after_json')
        ->assertDontSee('hidden');
});

it('exports pre enrollments for leadership reporting', function () {
    Excel::fake();
    $admin = odessaPreEnrollmentAdmin(['odessa-pre-enrollments.view']);
    OdessaPreEnrollment::factory()->create(['medical_attention_identifier' => '3333333333']);

    $this->actingAs($admin)
        ->get(route('admin.odessa.pre-enrollments.export'))
        ->assertOk();

    Excel::assertDownloaded('odessa-preafiliaciones-'.now()->format('Y-m-d_His').'.xlsx', fn (OdessaPreEnrollmentsExport $export) => true);
});

it('does not map credit numbers or linked FAMEDIC email into the export rows', function () {
    $linkedUser = User::factory()->create(['email' => 'linked-export@example.test']);
    $preEnrollment = OdessaPreEnrollment::factory()->make([
        'medical_attention_identifier' => '3333333333',
        'linked_user_id' => $linkedUser->id,
    ]);
    $preEnrollment->setRelation('linkedUser', $linkedUser);

    $row = (new OdessaPreEnrollmentsExport())->map($preEnrollment);

    expect($row)->not->toContain('3333333333')
        ->and($row)->not->toContain('linked-export@example.test')
        ->and($row[9])->toBe('Sí')
        ->and($row[12])->toBe('Detectada');
});

it('sanitizes exported text cells that could be interpreted as formulas', function (string $prefix) {
    $preEnrollment = OdessaPreEnrollment::factory()->make([
        'company_external_identifier' => $prefix.'empresa',
        'employee_identifier' => $prefix.'empleado',
        'paternal_last_name' => $prefix.'paterno',
        'maternal_last_name' => $prefix.'materno',
        'first_name' => $prefix.'nombre',
    ]);

    $row = (new OdessaPreEnrollmentsExport())->map($preEnrollment);

    expect($row[0])->toStartWith("'");
})->with(['=', '+', '-', '@']);

it('rejects structurally excessive preview workbooks before persisting anything', function () {
    config(['famedic.odessa_pre_enrollments.import_max_rows' => 2]);
    $path = odessaPreEnrollmentWorkbook([
        ['employee' => '1201'],
        ['employee' => '1202'],
    ]);

    expect(fn () => app(OdessaPreEnrollmentPreviewService::class)->preview($path))
        ->toThrow(\InvalidArgumentException::class);

    expect(OdessaPreEnrollment::count())->toBe(0);
});

it('rejects workbooks with too many sheets rows or columns', function (array $config, string $pathType) {
    config($config);
    $path = match ($pathType) {
        'sheets' => odessaPreEnrollmentWorkbook([['employee' => '1201']], extraSheets: 2),
        'rows' => odessaPreEnrollmentWorkbook([['employee' => '1201'], ['employee' => '1202']]),
        'columns' => odessaPreEnrollmentWorkbook([['employee' => '1201']], extraColumns: 25),
    };

    expect(fn () => app(OdessaPreEnrollmentPreviewService::class)->preview($path))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'sheets' => [['famedic.odessa_pre_enrollments.import_max_sheets' => 1], 'sheets'],
    'rows' => [['famedic.odessa_pre_enrollments.import_max_rows' => 2], 'rows'],
    'columns' => [['famedic.odessa_pre_enrollments.import_max_columns' => 10], 'columns'],
]);

it('continues accepting the expected Sin Registro sheet', function () {
    $path = odessaPreEnrollmentWorkbook([['employee' => '1201']]);

    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path);

    expect($preview['meta']['row_count'])->toBe(1);
});

it('keeps customer generation compatible when reservation infrastructure is absent during rollout', function () {
    Schema::dropIfExists('medical_attention_identifier_reservations');

    $code = app(GenerateUniqueMedicalAttentionIdAction::class)();

    expect((string) $code)->toHaveLength(10);
});

it('rejects invalid Murguia explicit identifiers before assignment', function () {
    $user = User::factory()->withRegularCustomer()->create(['email' => 'synthetic@example.test']);
    $customer = $user->customer;
    $originalIdentifier = $customer->medical_attention_identifier;

    app(MurguiaInsuredExcelRowProcessor::class)->process([
        'email' => 'synthetic@example.test',
        'medical_attention_identifier' => '12345abcde',
        'accion' => 'alta',
    ], 2);

    expect($customer->fresh()->medical_attention_identifier)->toBe($originalIdentifier)
        ->and(MurguiaSyncLog::query()->where('message', 'murguia.identifier.invalid')->exists())->toBeTrue()
        ->and(MurguiaSyncLog::query()->where('medical_attention_identifier', '12345abcde')->exists())->toBeFalse();

    expectMurguiaLogsToBeSanitized();
});

it('centrally strips sensitive Murguia sync log fields on create and update', function () {
    $log = MurguiaSyncLog::create([
        'email' => 'sensitive@example.test',
        'medical_attention_identifier' => '7234567890',
        'action' => MurguiaSyncLog::ACTION_VALIDACION,
        'request_payload' => ['noCredito' => '7234567890', 'token' => 'secret-token'],
        'response_payload' => [
            'http_status' => 200,
            'result_code' => 'Registrado en Murguía',
            'body' => ['email' => 'sensitive@example.test', 'phone' => '5555555555'],
            'token' => 'secret-token',
        ],
        'status' => MurguiaSyncLog::STATUS_SUCCESS,
        'message' => 'Fila 3 sensitive@example.test 7234567890',
        'entry_type' => MurguiaSyncLog::ENTRY_TYPE_SINGLE,
    ])->fresh();

    expect($log->email)->toBeNull()
        ->and($log->medical_attention_identifier)->toBeNull()
        ->and($log->request_payload)->toBeNull()
        ->and($log->response_payload)->toHaveKeys(['http_status', 'result_code'])
        ->and(json_encode($log->response_payload))->not->toContain('sensitive@example.test', '7234567890', '5555555555', 'secret-token')
        ->and($log->message)->not->toContain('sensitive@example.test')
        ->and($log->message)->not->toContain('7234567890');

    $log->update([
        'email' => 'updated@example.test',
        'medical_attention_identifier' => '8234567890',
        'request_payload' => ['noCredito' => '8234567890'],
        'response_payload' => ['exception_type' => RuntimeException::class, 'password' => 'hidden'],
        'message' => 'Runtime failure with updated@example.test',
    ]);

    $log = $log->fresh();
    expect($log->email)->toBeNull()
        ->and($log->medical_attention_identifier)->toBeNull()
        ->and($log->request_payload)->toBeNull()
        ->and($log->response_payload)->toHaveKey('error_code')
        ->and(json_encode($log->response_payload))->not->toContain('password', 'hidden')
        ->and($log->message)->not->toContain('updated@example.test');
});

it('keeps Murguia monitor controller logs sanitized even when callers pass payloads', function () {
    $admin = odessaPreEnrollmentAdmin();
    $user = User::factory()->withRegularCustomer()->create(['email' => 'monitor@example.test']);
    $customer = $user->customer;
    $customer->update(['medical_attention_identifier' => '9234567890']);
    $request = Request::create('/admin/murguia-monitor/'.$customer->id.'/check-status', 'POST');
    $request->setUserResolver(fn () => $admin);
    $checkStatusAction = $this->mock(CheckStatusAction::class);
    $checkStatusAction->shouldReceive('__invoke')
        ->once()
        ->withArgs(fn ($argument) => $argument instanceof Customer && $argument->id === $customer->id)
        ->andReturn(odessaHttpResponse(200, ['success' => true, 'email' => 'monitor@example.test', 'noCredito' => '9234567890']));

    app(MurguiaMonitorController::class)->checkStatus($request, $customer, app(CheckStatusAction::class));

    expectMurguiaLogsToBeSanitized();
});

it('keeps single customer Murguia action logs sanitized', function () {
    $user = User::factory()->withRegularCustomer()->create(['email' => 'single-action@example.test']);
    $customer = $user->customer;
    $customer->update(['medical_attention_identifier' => null]);

    app(\App\Actions\Admin\MurguiaMonitorSingleCustomerAction::class)($customer->id, 'activate', null);

    expect(MurguiaSyncLog::query()->where('status', MurguiaSyncLog::STATUS_FAILED)->exists())->toBeTrue();
    expectMurguiaLogsToBeSanitized();
});

it('sanitizes Murguia logs on successful validation', function () {
    $user = User::factory()->withRegularCustomer()->create(['email' => 'synthetic-success@example.test']);
    $user->customer->update(['medical_attention_identifier' => '5234567890']);
    $this->mock(CheckStatusAction::class)
        ->shouldReceive('__invoke')
        ->once()
        ->andReturn(odessaHttpResponse(200, ['success' => true, 'noCredito' => '5234567890']));

    app(MurguiaInsuredExcelRowProcessor::class)->process([
        'email' => 'synthetic-success@example.test',
        'medical_attention_identifier' => '5234567890',
        'accion' => 'validacion',
    ], 9);

    expect(MurguiaSyncLog::query()->where('status', MurguiaSyncLog::STATUS_SUCCESS)->exists())->toBeTrue();
    expectMurguiaLogsToBeSanitized();
});

it('sanitizes Murguia logs on reservation failure', function () {
    User::factory()->create(['email' => 'synthetic-reservation@example.test']);
    $generator = $this->mock(GenerateUniqueMedicalAttentionIdAction::class);
    $generator->shouldReceive('isValidIdentifier')->andReturnTrue();
    $generator->shouldReceive('__invoke')->once()->andReturn(9234567890);
    $generator->shouldReceive('reserveExistingIdentifier')->once()->andReturnFalse();

    app(MurguiaInsuredExcelRowProcessor::class)->process([
        'email' => 'synthetic-reservation@example.test',
        'medical_attention_identifier' => '6234567890',
        'accion' => 'alta',
    ], 10);

    expect(MurguiaSyncLog::query()->where('message', 'murguia.activation.identifier_reserved')->exists())->toBeTrue();
    expectMurguiaLogsToBeSanitized();
});

it('sanitizes Murguia logs on integration failure', function () {
    $user = User::factory()->withRegularCustomer()->create(['email' => 'synthetic-failure@example.test']);
    $user->customer->update(['medical_attention_identifier' => '7234567890']);
    MedicalAttentionSubscription::factory()->create([
        'customer_id' => $user->customer->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addYear(),
        'price_cents' => 10000,
        'type' => MedicalSubscriptionType::REGULAR,
    ]);
    $this->mock(SyncSubscriptionToMurguiaAction::class)
        ->shouldReceive('__invoke')
        ->once()
        ->andReturnFalse();

    app(MurguiaInsuredExcelRowProcessor::class)->process([
        'email' => 'synthetic-failure@example.test',
        'medical_attention_identifier' => '7234567890',
        'accion' => 'alta',
    ], 11);

    expect(MurguiaSyncLog::query()->where('status', MurguiaSyncLog::STATUS_FAILED)->exists())->toBeTrue();
    expectMurguiaLogsToBeSanitized();
});
