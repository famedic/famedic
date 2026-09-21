<?php

namespace Tests\Feature\Admin;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultEventType;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultExtractionStatus;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultReportSource;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\Administrator;
use App\Models\AiExecution;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultEvent;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Laboratory\GdaResultsStorageIsolatedSchema;
use Tests\Feature\Laboratory\StructuredResultsIsolatedSchema;
use Tests\TestCase;

class LaboratoryResultsCenterTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
        $this->bootstrapAiExplanationSchema();
        $this->bootstrapAdminSchema();
        $this->seedPermissions();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureUserHasAdminAccount::class,
            \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('laboratory_result_ai_explanations');
        $this->tearDownStructuredResultsSchema();
        $this->tearDownIsolatedSchema();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function admin_autorizado_puede_acceder(): void
    {
        $admin = $this->seedAdminUser();

        $this->actingAs($admin)
            ->get(route('admin.laboratory-results-center.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LaboratoryResults/Center')
                ->has('purchases.data')
                ->has('summary', 5));
    }

    #[Test]
    public function usuario_sin_permiso_no_puede_acceder(): void
    {
        $admin = $this->seedAdminUser(withMonitorPermission: false);

        $this->actingAs($admin)
            ->get(route('admin.laboratory-results-center.index'))
            ->assertForbidden();
    }

    #[Test]
    public function listado_y_paginacion_funcionan(): void
    {
        $admin = $this->seedAdminUser();

        for ($i = 0; $i < 30; $i++) {
            $this->seedPurchase(['gda_order_id' => 'ORD-PAGE-'.$i]);
        }

        $this->actingAs($admin)
            ->get(route('admin.laboratory-results-center.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('purchases.data', 25)
                ->where('purchases.current_page', 1)
                ->where('purchases.per_page', 25));
    }

    #[Test]
    public function filtros_funcionan(): void
    {
        $admin = $this->seedAdminUser();
        $this->seedPurchase(['gda_order_id' => 'MATCH-ME']);
        $this->seedPurchase(['gda_order_id' => 'IGNORE-ME']);

        $this->actingAs($admin)
            ->get(route('admin.laboratory-results-center.index', ['folio' => 'MATCH']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('purchases.data', 1)
                ->where('purchases.data.0.folio', 'MATCH-ME'));
    }

    #[Test]
    public function purchase_sin_resultado_es_not_available(): void
    {
        $admin = $this->seedAdminUser();
        $purchase = $this->seedPurchase();

        $this->assertRowStatus($admin, $purchase, 'NOT_AVAILABLE');
    }

    #[Test]
    public function purchase_con_pdf_sin_report_es_received(): void
    {
        $admin = $this->seedAdminUser();
        $purchase = $this->seedPurchaseWithVersion();

        $this->assertRowStatus($admin, $purchase, 'RECEIVED');
    }

    #[Test]
    public function extraction_pending_es_processing(): void
    {
        $admin = $this->seedAdminUser();
        [$purchase, $version] = $this->seedPurchaseWithVersion(returnVersion: true);
        $this->seedReport($purchase, $version, extractionStatus: LaboratoryResultExtractionStatus::Pending);

        $this->assertRowStatus($admin, $purchase, 'PROCESSING');
    }

    #[Test]
    public function report_estructurado_es_structured(): void
    {
        $admin = $this->seedAdminUser();
        [$purchase, $version] = $this->seedPurchaseWithVersion(returnVersion: true);
        $this->seedReport($purchase, $version, observationCount: 2, structuredStatus: LaboratoryResultStructuredStatus::Validated);

        $this->assertRowStatus($admin, $purchase, 'STRUCTURED');
    }

    #[Test]
    public function validation_manual_review_es_review(): void
    {
        $admin = $this->seedAdminUser();
        [$purchase, $version] = $this->seedPurchaseWithVersion(returnVersion: true);
        $this->seedReport(
            $purchase,
            $version,
            extractionStatus: LaboratoryResultExtractionStatus::Partial,
            observationCount: 1,
            validationErrors: [['error_code' => 'unit_missing']]
        );

        $this->assertRowStatus($admin, $purchase, 'REVIEW');
    }

    #[Test]
    public function report_publicado_es_published(): void
    {
        $admin = $this->seedAdminUser();
        [$purchase, $version] = $this->seedPurchaseWithVersion(returnVersion: true);
        $this->seedReport(
            $purchase,
            $version,
            extractionStatus: LaboratoryResultExtractionStatus::Extracted,
            structuredStatus: LaboratoryResultStructuredStatus::Published,
            observationCount: 1,
            published: true,
        );

        $this->assertRowStatus($admin, $purchase, 'PUBLISHED');
    }

    #[Test]
    public function extraction_failed_es_failed(): void
    {
        $admin = $this->seedAdminUser();
        [$purchase, $version] = $this->seedPurchaseWithVersion(returnVersion: true);
        $this->seedReport($purchase, $version, extractionStatus: LaboratoryResultExtractionStatus::Failed);

        $this->assertRowStatus($admin, $purchase, 'FAILED');
    }

    #[Test]
    public function errores_se_sanitizan_y_no_exponen_pii_ni_secretos_ni_raw_payload(): void
    {
        $admin = $this->seedAdminUser();
        [$purchase, $version] = $this->seedPurchaseWithVersion(returnVersion: true);
        $report = $this->seedReport(
            $purchase,
            $version,
            extractionStatus: LaboratoryResultExtractionStatus::Failed,
            validationErrors: [[
                'error_code' => 'bad_output',
                'errors' => ['secret@example.com token sk-testsecret1234567890'],
            ]],
            rawPayload: ['prompt' => 'NO DEBE SALIR', 'api_key' => 'sk-rawsecret'],
        );

        $execution = AiExecution::query()->create([
            'domain' => 'laboratory_results',
            'feature' => 'ai_explanation',
            'status' => AiExecution::STATUS_FAILED,
            'error' => 'Bearer abc.secret.token email secret@example.com key sk-ai-secret',
        ]);
        $report->update(['ai_execution_id' => $execution->id]);

        $response = $this->actingAs($admin)
            ->get(route('admin.laboratory-results-center.show', $purchase))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LaboratoryResults/Center')
                ->has('detail.errors')
                ->missing('detail.pipeline.0.details.raw_extraction_payload'));

        $response
            ->assertDontSee('sk-testsecret1234567890')
            ->assertDontSee('sk-rawsecret')
            ->assertDontSee('sk-ai-secret')
            ->assertDontSee('secret@example.com')
            ->assertDontSee('NO DEBE SALIR')
            ->assertDontSee('api_key');
    }

    private function assertRowStatus(User $admin, LaboratoryPurchase $purchase, string $status): void
    {
        $this->actingAs($admin)
            ->get(route('admin.laboratory-results-center.index', ['purchase_id' => $purchase->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('purchases.data', 1)
                ->where('purchases.data.0.status', $status));
    }

    private function seedPurchase(array $attributes = []): LaboratoryPurchase
    {
        $user = User::query()->create([
            'name' => 'Paciente Centro',
            'email' => 'patient-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $customer = Customer::query()->create(['user_id' => $user->id]);

        return LaboratoryPurchase::query()->create(array_merge([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-'.uniqid(),
            'name' => 'Paciente',
            'paternal_lastname' => 'Centro',
            'maternal_lastname' => 'Resultados',
            'phone' => '8112345678',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'NL',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /**
     * @return LaboratoryPurchase|array{0: LaboratoryPurchase, 1: LaboratoryResultVersion}
     */
    private function seedPurchaseWithVersion(bool $returnVersion = false): LaboratoryPurchase|array
    {
        $purchase = $this->seedPurchase();
        $item = LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'GDA-'.$purchase->id,
            'name' => 'Biometria',
            'price_cents' => 10000,
        ]);
        $status = LaboratoryResultStatus::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_purchase_item_id' => $item->id,
            'status' => 'complete',
            'first_available_at' => now(),
            'last_checked_at' => now(),
        ]);
        $version = LaboratoryResultVersion::query()->create([
            'laboratory_result_status_id' => $status->id,
            'storage_path' => 'laboratory-results/'.$purchase->id.'.pdf',
            'sha256' => hash('sha256', 'pdf-'.$purchase->id),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'test',
            'matched_rule' => 'test',
            'classifier' => 'test',
            'classified_at' => now(),
            'pdf_available_at' => now(),
        ]);

        return $returnVersion ? [$purchase, $version] : $purchase;
    }

    private function seedReport(
        LaboratoryPurchase $purchase,
        LaboratoryResultVersion $version,
        LaboratoryResultExtractionStatus $extractionStatus = LaboratoryResultExtractionStatus::Extracted,
        LaboratoryResultStructuredStatus $structuredStatus = LaboratoryResultStructuredStatus::Draft,
        int $observationCount = 0,
        ?array $validationErrors = null,
        bool $published = false,
        ?array $rawPayload = null,
    ): LaboratoryResultReport {
        $report = LaboratoryResultReport::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_result_version_id' => $version->id,
            'source' => LaboratoryResultReportSource::Gda,
            'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
            'extraction_status' => $extractionStatus,
            'structured_status' => $structuredStatus,
            'confidence_overall' => 0.9,
            'observation_count' => $observationCount,
            'validation_errors' => $validationErrors,
            'raw_extraction_payload' => $rawPayload,
            'published_at' => $published ? now() : null,
            'published_version_slot' => $published ? $version->id : null,
            'input_hash' => hash('sha256', 'report-'.$purchase->id.'-'.$extractionStatus->value.'-'.uniqid()),
        ]);

        for ($i = 0; $i < $observationCount; $i++) {
            LaboratoryResultObservation::query()->create([
                'laboratory_result_report_id' => $report->id,
                'analyte_name_raw' => 'Glucosa',
                'numeric_value' => 90,
                'value_type' => LaboratoryResultObservationValueType::Numeric,
                'unit' => 'mg/dL',
                'reference_text' => '70-100',
                'reference_status' => LaboratoryResultReferenceStatus::Normal,
                'extraction_method' => LaboratoryResultExtractionMethod::PdfText,
                'confidence' => 0.9,
            ]);
        }

        if ($extractionStatus === LaboratoryResultExtractionStatus::Failed) {
            LaboratoryResultEvent::query()->create([
                'laboratory_result_status_id' => $version->laboratory_result_status_id,
                'laboratory_result_version_id' => $version->id,
                'event_type' => LaboratoryResultEventType::ExtractionFailed,
                'metadata' => ['error_code' => 'pdf_extract_failed'],
                'created_at' => now(),
            ]);
        }

        return $report;
    }

    private function seedAdminUser(bool $withMonitorPermission = true): User
    {
        $user = User::query()->create([
            'name' => 'Admin Centro',
            'email' => 'admin-centro-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $adminId = DB::table('administrators')->insertGetId([
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withMonitorPermission) {
            $permission = Permission::findOrCreate('laboratory-notifications.monitor', 'web');

            DB::table('model_has_permissions')->insert([
                'permission_id' => $permission->id,
                'model_type' => Administrator::class,
                'model_id' => $adminId,
            ]);
        }

        return $user->fresh('administrator');
    }

    private function seedPermissions(): void
    {
        foreach (['laboratory-notifications.monitor', 'laboratory-purchases.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function bootstrapAdminSchema(): void
    {
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('administrators');

        Schema::create('administrators', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('permissions', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('roles', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('model_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_type', 'model_id']);
        });

        Schema::create('model_has_roles', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_type', 'model_id']);
        });

        Schema::create('role_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
    }

    private function bootstrapAiExplanationSchema(): void
    {
        Schema::dropIfExists('laboratory_result_ai_explanations');

        Schema::create('laboratory_result_ai_explanations', function ($table) {
            $table->id();
            $table->foreignId('laboratory_result_observation_id')->constrained('laboratory_result_observations')->cascadeOnDelete();
            $table->string('status', 30);
            $table->text('explanation')->nullable();
            $table->text('limitations')->nullable();
            $table->string('input_hash', 64);
            $table->unsignedInteger('prompt_version');
            $table->foreignId('ai_execution_id')->nullable()->constrained('ai_executions')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }
}
