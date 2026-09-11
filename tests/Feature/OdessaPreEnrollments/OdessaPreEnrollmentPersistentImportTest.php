<?php

use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use App\Models\OdessaAfiliateAccount;
use App\Models\OdessaPreEnrollment;
use App\Models\OdessaPreEnrollmentImportRun;
use App\Models\OdessaPreEnrollmentImportRunAudit;
use App\Models\OdessaPreEnrollmentImportRunRow;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Odessa\PreEnrollment\OdessaPreEnrollmentImportService;
use App\Services\Odessa\PreEnrollment\OdessaPreEnrollmentExcelParser;
use App\Services\Odessa\PreEnrollment\OdessaPreEnrollmentPreviewService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    config([
        'famedic.odessa_pre_enrollments.enabled' => true,
        'famedic.odessa_pre_enrollments.import_enabled' => true,
    ]);
    Http::fake();
});

function odessaImportAdmin(array $permissions = [
    'odessa-pre-enrollments.view',
    'odessa-pre-enrollments.manage',
    'odessa-pre-enrollments.actions.import',
]): User {
    $role = Role::firstOrCreate(['name' => 'PreEnrollment Import Admin '.md5(implode('|', $permissions)), 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    $user = User::factory()->withAdministrator()->create();
    $user->administrator->assignRole($role);

    return $user->fresh('administrator.roles.permissions');
}

function odessaImportWorkbook(array $rows): string
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Sin Registro');
    $sheet->fromArray([
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
    ], null, 'A1');

    foreach ($rows as $index => $row) {
        $sheet->fromArray([
            $row['company'] ?? '5000',
            $row['employee'] ?? (string) (9000 + $index),
            $row['paternal'] ?? 'Importable',
            $row['maternal'] ?? 'Seguro',
            $row['name'] ?? 'Persona',
            $row['birth_date'] ?? '1990-01-01',
            $row['email'] ?? "import{$index}@odessa.test",
            $row['odessa'] ?? null,
            $row['action'] ?? 'ALTA',
            'NO_REGISTRADO_EN_FAMEDIC',
        ], null, 'A'.($index + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'odessa-import-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function odessaImportUpload(string $path): UploadedFile
{
    return new UploadedFile(
        $path,
        'preafiliaciones.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );
}

it('creates a sanitized import run during preview', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([
        ['employee' => '9101', 'email' => 'ready@example.test'],
    ]);

    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    $run = OdessaPreEnrollmentImportRun::query()->where('uuid', $preview['meta']['run_uuid'])->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(OdessaPreEnrollmentImportRun::STATUS_PREVIEWED)
        ->and($run->ready_rows)->toBe(1)
        ->and(OdessaPreEnrollmentImportRunRow::where('import_run_id', $run->id)->count())->toBe(1)
        ->and(json_encode($preview))->not->toContain('source_file_hash', 'source_row_hash', 'ready@example.test', 'preafiliaciones.xlsx')
        ->and($run->getAttributes())->not->toHaveKeys(['filename', 'path']);
});

it('blocks expired import runs', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([['employee' => '9102']]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    OdessaPreEnrollmentImportRun::where('uuid', $preview['meta']['run_uuid'])->update(['expires_at' => now()->subMinute()]);

    $result = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $path, $admin);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('run_expired')
        ->and(OdessaPreEnrollmentImportRun::where('uuid', $preview['meta']['run_uuid'])->value('status'))->toBe(OdessaPreEnrollmentImportRun::STATUS_EXPIRED)
        ->and(OdessaPreEnrollmentImportRun::where('uuid', $preview['meta']['run_uuid'])->value('row_hmac_key_encrypted'))->toBeNull()
        ->and(OdessaPreEnrollmentImportRun::where('uuid', $preview['meta']['run_uuid'])->value('source_file_hash'))->toBeNull()
        ->and(OdessaPreEnrollmentImportRunRow::where('import_run_id', OdessaPreEnrollmentImportRun::where('uuid', $preview['meta']['run_uuid'])->value('id'))->count())->toBe(0)
        ->and(OdessaPreEnrollment::count())->toBe(0);
});

it('rejects a different confirmation file', function () {
    $admin = odessaImportAdmin();
    $previewPath = odessaImportWorkbook([['employee' => '9103']]);
    $confirmPath = odessaImportWorkbook([['employee' => '9104']]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($previewPath, $admin);

    $result = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $confirmPath, $admin);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('file_hash_mismatch')
        ->and(OdessaPreEnrollment::count())->toBe(0);
});

it('rejects mismatched counts and modified rows before importing', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([['employee' => '9105']]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    OdessaPreEnrollmentImportRun::where('uuid', $preview['meta']['run_uuid'])->update(['ready_rows' => 2]);

    $countsResult = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $path, $admin);

    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    $changedPath = odessaImportWorkbook([['employee' => '9105', 'name' => 'Cambiado']]);
    $changedHash = hash_file('sha256', $changedPath);
    OdessaPreEnrollmentImportRun::where('uuid', $preview['meta']['run_uuid'])->update(['source_file_hash' => $changedHash]);

    $rowResult = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $changedPath, $admin);

    expect($countsResult['code'])->toBe('counts_mismatch')
        ->and($rowResult['code'])->toBe('row_hash_mismatch')
        ->and(OdessaPreEnrollment::count())->toBe(0);
});

it('imports only READY_TO_PRELOAD rows and creates no external FAMEDIC entities', function () {
    $admin = odessaImportAdmin();
    User::factory()->withRegularCustomer()->create([
        'name' => 'Existente',
        'paternal_lastname' => 'Persona',
        'maternal_lastname' => 'Famedic',
        'birth_date' => '1990-01-01',
        'email' => 'existente.personal@example.test',
    ]);
    $before = [
        'users' => User::count(),
        'customers' => Customer::count(),
        'odessa' => OdessaAfiliateAccount::count(),
        'subscriptions' => MedicalAttentionSubscription::count(),
    ];
    $path = odessaImportWorkbook([
        ['employee' => '9106', 'name' => 'Nueva', 'paternal' => 'Lista', 'maternal' => 'Uno', 'birth_date' => '1991-01-01', 'email' => 'nueva@example.test'],
        ['employee' => '9107', 'name' => 'Existente', 'paternal' => 'Persona', 'maternal' => 'Famedic', 'birth_date' => '1990-01-01', 'email' => 'existente@odessa.test'],
        ['employee' => '9108', 'name' => 'Duplicada', 'paternal' => 'Carga', 'maternal' => 'Dos', 'birth_date' => '1992-01-01', 'email' => 'dup@example.test'],
        ['employee' => '9109', 'name' => 'Duplicada', 'paternal' => 'Carga', 'maternal' => 'Dos', 'birth_date' => '1992-01-01', 'email' => 'dup@example.test'],
        ['employee' => '9110', 'name' => '', 'paternal' => 'Bloqueada', 'birth_date' => ''],
    ]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);

    $result = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $path, $admin);
    $imported = OdessaPreEnrollment::first();

    expect($result['ok'])->toBeTrue()
        ->and($result['created'])->toBe(1)
        ->and(OdessaPreEnrollment::count())->toBe(1)
        ->and($imported->status)->toBe(OdessaPreEnrollment::STATUS_READY)
        ->and($imported->link_status)->toBe(OdessaPreEnrollment::LINK_PENDING_ACCOUNT)
        ->and($imported->murguia_status)->toBe(OdessaPreEnrollment::MURGUIA_NOT_REQUESTED)
        ->and($imported->medical_attention_identifier)->toBeNull()
        ->and(User::count())->toBe($before['users'])
        ->and(Customer::count())->toBe($before['customers'])
        ->and(OdessaAfiliateAccount::count())->toBe($before['odessa'])
        ->and(MedicalAttentionSubscription::count())->toBe($before['subscriptions']);

    Http::assertNothingSent();
});

it('does not duplicate records on repeated confirmation of the same run', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([
        ['employee' => '9111', 'name' => 'Primera', 'paternal' => 'Importable', 'birth_date' => '1991-01-01', 'email' => 'primera@example.test'],
        ['employee' => '9112', 'name' => 'Segunda', 'paternal' => 'Importable', 'birth_date' => '1992-01-01', 'email' => 'segunda@example.test'],
    ]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);

    $first = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $path, $admin);
    $second = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $path, $admin);

    expect($first['ok'])->toBeTrue()
        ->and($second['ok'])->toBeTrue()
        ->and($second['replay'])->toBeTrue()
        ->and(OdessaPreEnrollment::count())->toBe(2);
});

it('rolls back completely when a collision appears after preview', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([
        ['employee' => '9113'],
        ['employee' => '9114'],
    ]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    $run = OdessaPreEnrollmentImportRun::where('uuid', $preview['meta']['run_uuid'])->first();
    OdessaPreEnrollment::factory()->create([
        'source_file_hash' => $run->source_file_hash,
        'source_sheet' => 'Sin Registro',
        'source_row' => 3,
        'company_external_identifier' => '9900',
        'employee_identifier' => '9914',
    ]);

    $result = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $path, $admin);

    expect($result['ok'])->toBeFalse()
        ->and(OdessaPreEnrollment::count())->toBe(1)
        ->and(OdessaPreEnrollment::where('import_run_id', $run->id)->count())->toBe(0);
});

it('requires import flag and explicit import permission', function () {
    $path = odessaImportWorkbook([['employee' => '9115']]);
    $admin = odessaImportAdmin();
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);

    config(['famedic.odessa_pre_enrollments.import_enabled' => false]);
    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.import.confirm'), [
            'run_uuid' => $preview['meta']['run_uuid'],
            'source_file' => odessaImportUpload($path),
            'confirmation' => 'IMPORTAR',
        ])
        ->assertSessionHasErrors('import');

    config(['famedic.odessa_pre_enrollments.import_enabled' => true]);
    $this->actingAs(odessaImportAdmin(['odessa-pre-enrollments.view', 'odessa-pre-enrollments.manage']))
        ->post(route('admin.odessa.pre-enrollments.import.confirm'), [
            'run_uuid' => $preview['meta']['run_uuid'],
            'source_file' => odessaImportUpload($path),
            'confirmation' => 'IMPORTAR',
        ])
        ->assertForbidden();

    $this->actingAs(odessaImportAdmin(['odessa-pre-enrollments.view']))
        ->post(route('admin.odessa.pre-enrollments.import.confirm'), [
            'run_uuid' => $preview['meta']['run_uuid'],
            'source_file' => odessaImportUpload($path),
            'confirmation' => 'IMPORTAR',
        ])
        ->assertForbidden();

    expect(OdessaPreEnrollment::count())->toBe(0);
});

it('keeps import audit and preview props sanitized', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([['employee' => '9116', 'email' => 'audit-private@example.test']]);

    $response = $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.import.preview'), [
            'source_file' => odessaImportUpload($path),
        ])
        ->assertOk();

    $run = OdessaPreEnrollmentImportRun::first();
    $auditPayload = json_encode(OdessaPreEnrollmentImportRunAudit::query()->get()->toArray());

    $response->assertDontSee('source_file_hash')
        ->assertDontSee('source_row_hash')
        ->assertDontSee('audit-private@example.test')
        ->assertDontSee('preafiliaciones.xlsx');

    expect($run)->not->toBeNull()
        ->and($auditPayload)->not->toContain('audit-private@example.test')
        ->and($auditPayload)->not->toContain($run->source_file_hash)
        ->and(Schema::getColumnListing('odessa_pre_enrollment_import_runs'))->not->toContain('filename', 'path');
});

it('parses and computes row HMACs outside the confirmation transaction', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([['employee' => '9117']]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    $baseTransactionLevel = DB::transactionLevel();
    $transactionLevels = [];

    app()->instance(OdessaPreEnrollmentPreviewService::class, new class(app(OdessaPreEnrollmentExcelParser::class), $transactionLevels) extends OdessaPreEnrollmentPreviewService {
        public function __construct(OdessaPreEnrollmentExcelParser $parser, private array &$transactionLevels)
        {
            parent::__construct($parser);
        }

        public function analyze(UploadedFile|string $file): array
        {
            $this->transactionLevels[] = DB::transactionLevel();

            return parent::analyze($file);
        }

        public function rowHashes(array $analysis, string $key): array
        {
            $this->transactionLevels[] = DB::transactionLevel();

            return parent::rowHashes($analysis, $key);
        }
    });

    $result = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $path, $admin);

    expect($result['ok'])->toBeTrue()
        ->and($transactionLevels)->not->toBeEmpty()
        ->and(array_unique($transactionLevels))->toBe([$baseTransactionLevel]);
});

it('does not allow one administrator to confirm another administrators run', function () {
    $owner = odessaImportAdmin();
    $other = odessaImportAdmin();
    $path = odessaImportWorkbook([['employee' => '9118']]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $owner);

    $result = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $path, $other);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('invalid_import_run')
        ->and($result['message'])->not->toContain((string) $owner->id, (string) $other->id, $preview['meta']['run_uuid'])
        ->and(OdessaPreEnrollment::count())->toBe(0);
});

it('uses per-run HMACs and hides file hashes keys and row digests from serialization', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([['employee' => '9119', 'email' => 'hmac-private@example.test']]);
    $firstPreview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    $secondPreview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    $firstRun = OdessaPreEnrollmentImportRun::where('uuid', $firstPreview['meta']['run_uuid'])->first();
    $secondRun = OdessaPreEnrollmentImportRun::where('uuid', $secondPreview['meta']['run_uuid'])->first();
    $firstDigest = OdessaPreEnrollmentImportRunRow::where('import_run_id', $firstRun->id)->value('source_row_hash');
    $secondDigest = OdessaPreEnrollmentImportRunRow::where('import_run_id', $secondRun->id)->value('source_row_hash');

    expect($firstRun->row_hmac_key_encrypted)->not->toBeNull()
        ->and(Crypt::decryptString($firstRun->row_hmac_key_encrypted))->not->toEqual(Crypt::decryptString($secondRun->row_hmac_key_encrypted))
        ->and($firstDigest)->not->toEqual($secondDigest)
        ->and(json_encode($firstRun->toArray()))->not->toContain('source_file_hash', 'row_hmac_key_encrypted', $firstRun->source_file_hash)
        ->and(json_encode(OdessaPreEnrollmentImportRunRow::where('import_run_id', $firstRun->id)->first()->toArray()))->not->toContain('source_row_hash', $firstDigest);
});

it('cleans manifest and HMAC key after completed imports and stores no snapshot', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([['employee' => '9120']]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    $run = OdessaPreEnrollmentImportRun::where('uuid', $preview['meta']['run_uuid'])->first();

    $result = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $path, $admin);
    $imported = OdessaPreEnrollment::first();

    expect($result['ok'])->toBeTrue()
        ->and($run->fresh()->row_hmac_key_encrypted)->toBeNull()
        ->and(OdessaPreEnrollmentImportRunRow::where('import_run_id', $run->id)->count())->toBe(0)
        ->and($imported->source_snapshot_json)->toBeNull()
        ->and(Schema::getColumnListing('odessa_pre_enrollments'))->not->toContain('source_row_hash');
});

it('completes a second run of an already imported file as cross-run replay', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([['employee' => '9121']]);
    $firstPreview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    $secondPreview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);

    $first = app(OdessaPreEnrollmentImportService::class)->confirm($firstPreview['meta']['run_uuid'], $path, $admin);
    $second = app(OdessaPreEnrollmentImportService::class)->confirm($secondPreview['meta']['run_uuid'], $path, $admin);

    expect($first['ok'])->toBeTrue()
        ->and($second['ok'])->toBeTrue()
        ->and($second['replay'])->toBeTrue()
        ->and($second['created'])->toBe(0)
        ->and(OdessaPreEnrollment::count())->toBe(1);
});

it('aborts partial cross-run existence without inserting new rows', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([
        ['employee' => '9122', 'name' => 'Parcial', 'paternal' => 'Uno', 'birth_date' => '1991-01-01', 'email' => 'parcial.uno@example.test'],
        ['employee' => '9123', 'name' => 'Parcial', 'paternal' => 'Dos', 'birth_date' => '1992-01-01', 'email' => 'parcial.dos@example.test'],
    ]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    $run = OdessaPreEnrollmentImportRun::where('uuid', $preview['meta']['run_uuid'])->first();
    OdessaPreEnrollment::factory()->create([
        'source_file_hash' => $run->source_file_hash,
        'source_sheet' => 'Sin Registro',
        'source_row' => 2,
    ]);

    $result = app(OdessaPreEnrollmentImportService::class)->confirm($preview['meta']['run_uuid'], $path, $admin);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('IMPORT_PARTIAL_CONFLICT')
        ->and(OdessaPreEnrollment::count())->toBe(1);
});

it('prunes expired and retained import runs without exposing identifiers', function () {
    $admin = odessaImportAdmin();
    $path = odessaImportWorkbook([['employee' => '9124']]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($path, $admin);
    $expired = OdessaPreEnrollmentImportRun::where('uuid', $preview['meta']['run_uuid'])->first();
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    $old = OdessaPreEnrollmentImportRun::create([
        'source_file_hash' => hash('sha256', 'old'),
        'source_sheet' => 'Sin Registro',
        'status' => OdessaPreEnrollmentImportRun::STATUS_FAILED,
        'previewed_by' => $admin->id,
        'previewed_at' => now()->subDays(100),
    ]);
    $old->forceFill([
        'created_at' => now()->subDays(100),
        'updated_at' => now()->subDays(100),
    ])->save();

    Artisan::call('odessa:prune-pre-enrollment-import-runs');
    $output = Artisan::output();

    expect($expired->fresh()->status)->toBe(OdessaPreEnrollmentImportRun::STATUS_EXPIRED)
        ->and($expired->fresh()->source_file_hash)->toBeNull()
        ->and($expired->fresh()->row_hmac_key_encrypted)->toBeNull()
        ->and(OdessaPreEnrollmentImportRunRow::where('import_run_id', $expired->id)->count())->toBe(0)
        ->and(OdessaPreEnrollmentImportRun::whereKey($old->id)->exists())->toBeFalse()
        ->and($output)->toContain('expired=1')
        ->and($output)->not->toContain($expired->uuid, (string) $expired->source_file_hash);
});

it('rate limits preview and confirmation by named user buckets', function () {
    $admin = odessaImportAdmin();
    RateLimiter::clear((string) $admin->id);
    $path = odessaImportWorkbook([['employee' => '9125']]);

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($admin)
            ->post(route('admin.odessa.pre-enrollments.import.preview'), [
                'source_file' => odessaImportUpload($path),
            ])
            ->assertOk();
    }

    $this->actingAs($admin)
        ->post(route('admin.odessa.pre-enrollments.import.preview'), [
            'source_file' => odessaImportUpload($path),
        ])
        ->assertTooManyRequests();

    $confirmAdmin = odessaImportAdmin();
    RateLimiter::clear((string) $confirmAdmin->id);
    $confirmPath = odessaImportWorkbook([['employee' => '9126']]);
    $preview = app(OdessaPreEnrollmentPreviewService::class)->preview($confirmPath, $confirmAdmin);

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($confirmAdmin)
            ->post(route('admin.odessa.pre-enrollments.import.confirm'), [
                'run_uuid' => $preview['meta']['run_uuid'],
                'source_file' => odessaImportUpload($confirmPath),
                'confirmation' => 'IMPORTAR',
            ])
            ->assertRedirect();
    }

    $this->actingAs($confirmAdmin)
        ->post(route('admin.odessa.pre-enrollments.import.confirm'), [
            'run_uuid' => $preview['meta']['run_uuid'],
            'source_file' => odessaImportUpload($confirmPath),
            'confirmation' => 'IMPORTAR',
        ])
        ->assertTooManyRequests();
});
