<?php

namespace Tests\Feature\Admin;

use App\Enums\Gender;
use App\Enums\LaboratoryAnalyteValueKind;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Enums\LaboratoryStructuredResultPublicationApprovalStatus;
use App\Models\Customer;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryPatientStructuredResultQuery;
use App\Services\LaboratoryResults\StructuredQa\LaboratoryStructuredResultApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Laboratory\GdaResultsStorageIsolatedSchema;
use Tests\Feature\Laboratory\StructuredResultsIsolatedSchema;
use Tests\TestCase;

class LaboratoryStructuredQaPublicationTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
        $this->bootstrapAdminSchema();
        $this->seedAdminNavigationPermissions();
        Config::set('laboratory-results.structured_publication.enabled', true);

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            \Illuminate\Auth\Middleware\RequirePassword::class,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownStructuredResultsSchema();
        $this->tearDownIsolatedSchema();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function usuario_sin_permiso_publish_recibe_forbidden(): void
    {
        $admin = $this->seedAdminUser(withPublish: false);
        $report = $this->seedApprovedReport();

        $this->actingAs($admin)
            ->post(route('admin.laboratory-results.shadow-qa.publish', $report))
            ->assertForbidden();
    }

    #[Test]
    public function admin_puede_publicar_report_aprobado(): void
    {
        $admin = $this->seedAdminUser(withPublish: true);
        $report = $this->seedApprovedReport();

        $this->actingAs($admin)
            ->post(route('admin.laboratory-results.shadow-qa.publish', $report))
            ->assertRedirect();

        $report->refresh();
        $this->assertNotNull($report->published_version_slot);
        $this->assertSame(LaboratoryResultStructuredStatus::Published, $report->structured_status);
    }

    #[Test]
    public function paciente_correcto_recibe_resultado_tras_publicacion(): void
    {
        $admin = $this->seedAdminUser(withPublish: true);
        [$report, $purchase] = $this->seedApprovedReport(returnPurchase: true);

        $this->actingAs($admin)
            ->post(route('admin.laboratory-results.shadow-qa.publish', $report));

        $query = app(LaboratoryPatientStructuredResultQuery::class);
        $this->assertTrue($query->purchaseHasActivePublishedReport($purchase, $report->id));
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

    private function seedAdminNavigationPermissions(): void
    {
        $names = [
            'administrators.manage',
            'laboratory-purchases.manage',
            'laboratory-tests.manage',
            'laboratory-purchases.manage.vendor-payments',
            'online-pharmacy-purchases.manage',
            'online-pharmacy-purchases.manage.vendor-payments',
            'medical-attention-subscriptions.manage',
            'marketing-campaigns.manage',
            'marketing-campaigns.manage.edit',
            'marketing-campaigns.attributed-users.view-pii',
            'customers.manage',
            'coupons.manage',
            'documentation.manage',
            'simulators.manage',
            'logs-general.manage',
            'users.manage',
            'view carts',
            'efevoo-tokens.manage',
            'tax-profiles.manage',
            'payment-attempts.manage',
            'laboratory-notifications.monitor',
            'laboratory-results.approve-publication',
            'laboratory-results.publish',
            'view_config_monitor',
            'activecampaign.manage',
            'automation.manage',
            'clinical-interpreter.manage',
            'monitoring-ai.manage',
        ];

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function seedAdminUser(bool $withPublish = true): User
    {
        $user = User::query()->create([
            'name' => 'Publish Admin',
            'email' => 'pub-admin-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $adminId = DB::table('administrators')->insertGetId([
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissions = ['laboratory-notifications.monitor', 'laboratory-results.approve-publication'];
        if ($withPublish) {
            $permissions[] = 'laboratory-results.publish';
        }

        foreach ($permissions as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            DB::table('model_has_permissions')->insert([
                'permission_id' => $permission->id,
                'model_type' => \App\Models\Administrator::class,
                'model_id' => $adminId,
            ]);
        }

        return $user->fresh('administrator');
    }

    /**
     * @return LaboratoryResultReport|array{0: LaboratoryResultReport, 1: LaboratoryPurchase}
     */
    private function seedApprovedReport(bool $returnPurchase = false): LaboratoryResultReport|array
    {
        LaboratoryAnalyte::query()->create([
            'code' => 'FAMEDIC_CBC_HGB',
            'canonical_name' => 'Hemoglobina',
            'default_unit' => 'g/dL',
            'value_kind' => LaboratoryAnalyteValueKind::Numeric,
            'is_active' => true,
        ]);

        $admin = User::query()->create([
            'name' => 'Approver',
            'email' => 'approver-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $user = User::query()->create([
            'name' => 'Patient',
            'email' => 'patient-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);
        $customer = Customer::query()->create(['user_id' => $user->id]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-FEAT',
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'Feat',
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
        ]);

        $item = LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'GDA-FEAT',
            'name' => 'Panel',
            'price_cents' => 10000,
        ]);

        $status = LaboratoryResultStatus::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_purchase_item_id' => $item->id,
            'status' => 'complete',
            'first_available_at' => now(),
        ]);

        $version = LaboratoryResultVersion::query()->create([
            'laboratory_result_status_id' => $status->id,
            'storage_path' => 'results/gda-feat.pdf',
            'sha256' => hash('sha256', 'feat'),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'test',
            'matched_rule' => 'test',
            'classifier' => 'deterministic_pdf_v1',
            'classified_at' => now(),
        ]);

        $analyte = LaboratoryAnalyte::query()->first();

        $report = LaboratoryResultReport::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_result_version_id' => $version->id,
            'source' => \App\Enums\LaboratoryResultReportSource::Gda,
            'extraction_method' => LaboratoryResultExtractionMethod::Vision,
            'extraction_status' => \App\Enums\LaboratoryResultExtractionStatus::Extracted,
            'structured_status' => LaboratoryResultStructuredStatus::Validated,
            'observation_count' => 1,
            'input_hash' => hash('sha256', 'feat-report'),
            'published_version_slot' => null,
            'raw_extraction_payload' => ['shadow_qa' => true],
        ]);

        LaboratoryResultObservation::query()->create([
            'laboratory_result_report_id' => $report->id,
            'laboratory_analyte_id' => $analyte->id,
            'analyte_code' => $analyte->code,
            'analyte_name_raw' => 'HEMOGLOBINA',
            'numeric_value' => 14.4,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'unit' => 'g/dL',
            'reference_text' => '11.7 - 16.3',
            'reference_low' => 11.7,
            'reference_high' => 16.3,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
            'laboratory_purchase_item_id' => $item->id,
            'extraction_method' => LaboratoryResultExtractionMethod::Vision,
            'confidence' => 0.99,
            'metadata' => [
                'shadow_qa' => true,
                'purchase_association_method' => 'result_status_confirmed',
                'identity_evidence' => 'test',
                'reference_evaluation' => ['evaluated' => true],
                'promotion_evaluation' => [
                    'gate_version' => 'promotion_gate_v1',
                    'promotion_status' => 'validated',
                    'reason_codes' => [],
                    'reasons' => [],
                ],
            ],
        ]);

        app(LaboratoryStructuredResultApprovalService::class)->approve($report, $admin, 'Approved for pilot.');

        return $returnPurchase ? [$report->fresh(), $purchase] : $report->fresh();
    }
}
