<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryAnalyteValueKind;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultExtractionMethod;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Models\Customer;
use App\Models\LaboratoryAnalyte;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PatientStructuredResultsApiTest extends TestCase
{
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        config(['laboratory-results.otp_required' => false]);
        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
        $this->withoutPatientGateMiddleware();
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
    public function published_report_is_returned_for_owner(): void
    {
        [$owner, $purchase, $report] = $this->seedPublishedFixture();

        $response = $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase));

        $response->assertOk()
            ->assertJsonPath('data.report.id', $report->id)
            ->assertJsonPath('data.report.status', 'published')
            ->assertJsonPath('data.report.version_id', $report->laboratory_result_version_id)
            ->assertJsonCount(2, 'data.observations')
            ->assertJsonPath('data.meta.ai_explanation_enabled', false)
            ->assertJsonStructure([
                'data' => [
                    'meta' => ['ai_explanation_enabled'],
                    'observations' => [
                        ['id', 'analyte', 'value', 'unit', 'reference', 'status', 'abnormal'],
                    ],
                ],
            ]);
    }

    #[Test]
    public function shadow_report_is_excluded(): void
    {
        [$owner, $purchase, $version] = $this->seedPurchaseWithVersion();

        LaboratoryResultReport::factory()->forVersion($version)->create([
            'structured_status' => LaboratoryResultStructuredStatus::Draft,
            'extraction_method' => LaboratoryResultExtractionMethod::Vision,
            'raw_extraction_payload' => ['shadow_qa' => true],
            'input_hash' => hash('sha256', 'shadow-only'),
        ]);

        $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase))
            ->assertNotFound();
    }

    #[Test]
    public function draft_report_is_excluded(): void
    {
        [$owner, $purchase, $version] = $this->seedPurchaseWithVersion();

        LaboratoryResultReport::factory()->forVersion($version)->create([
            'structured_status' => LaboratoryResultStructuredStatus::Draft,
            'input_hash' => hash('sha256', 'draft-only'),
        ]);

        $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase))
            ->assertNotFound();
    }

    #[Test]
    public function validated_report_is_excluded(): void
    {
        [$owner, $purchase, $version] = $this->seedPurchaseWithVersion();

        LaboratoryResultReport::factory()->forVersion($version)->create([
            'structured_status' => LaboratoryResultStructuredStatus::Validated,
            'input_hash' => hash('sha256', 'validated-only'),
        ]);

        $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase))
            ->assertNotFound();
    }

    #[Test]
    public function approved_but_unpublished_report_is_excluded(): void
    {
        [$owner, $purchase, $version] = $this->seedPurchaseWithVersion();

        LaboratoryResultReport::factory()->forVersion($version)->create([
            'structured_status' => LaboratoryResultStructuredStatus::Validated,
            'raw_extraction_payload' => [
                'publication_approval' => [
                    'status' => 'approved',
                    'approved_at' => now()->toIso8601String(),
                ],
            ],
            'input_hash' => hash('sha256', 'approved-not-published'),
        ]);

        $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase))
            ->assertNotFound();
    }

    #[Test]
    public function superseded_report_is_excluded(): void
    {
        [$owner, $purchase, $version] = $this->seedPurchaseWithVersion();

        $superseded = LaboratoryResultReport::factory()->published($version)->create([
            'input_hash' => hash('sha256', 'superseded-report'),
        ]);

        $superseded->update([
            'structured_status' => LaboratoryResultStructuredStatus::Superseded,
            'superseded_at' => now(),
            'published_version_slot' => null,
        ]);

        $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase))
            ->assertNotFound();
    }

    #[Test]
    public function owner_can_access_own_purchase(): void
    {
        [$owner, $purchase] = $this->seedPublishedFixture();

        $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase))
            ->assertOk();
    }

    #[Test]
    public function cross_customer_access_is_blocked(): void
    {
        [, $purchase] = $this->seedPublishedFixture();
        $otherUser = $this->createOtherCustomerUser();

        $this->actingAs($otherUser)
            ->getJson(route('laboratory-purchases.structured-results', $purchase))
            ->assertForbidden();
    }

    #[Test]
    public function guest_is_unauthorized(): void
    {
        [, $purchase] = $this->seedPublishedFixture();

        $this->getJson(route('laboratory-purchases.structured-results', $purchase))
            ->assertUnauthorized();
    }

    #[Test]
    public function only_patient_safe_fields_are_exposed(): void
    {
        [$owner, $purchase, $report] = $this->seedPublishedFixture();

        $response = $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase));

        $payload = json_encode($response->json(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('raw_extraction_payload', $payload);
        $this->assertStringNotContainsString('publication_approval', $payload);
        $this->assertStringNotContainsString('promotion_gate', $payload);
        $this->assertStringNotContainsString('input_hash', $payload);
        $this->assertStringNotContainsString('prompt_version', $payload);
        $this->assertStringNotContainsString('confidence', $payload);
        $this->assertStringNotContainsString('metadata', $payload);
        $this->assertStringNotContainsString('source_bbox', $payload);
        $this->assertStringNotContainsString('pdf_base64', $payload);
        $this->assertStringNotContainsString('ai_execution', $payload);

        $response->assertJsonStructure([
            'data' => [
                'report' => [
                    'id',
                    'version_id',
                    'status',
                    'source',
                    'reported_at',
                    'specimen_collected_at',
                ],
                'observations' => [
                    [
                        'analyte' => ['code', 'name'],
                        'value',
                        'value_type',
                        'unit',
                        'reference' => ['text', 'low', 'high'],
                        'status',
                        'abnormal',
                    ],
                ],
            ],
        ]);

        $this->assertSame($report->id, $response->json('data.report.id'));
    }

    #[Test]
    public function out_of_range_status_and_reference_are_returned(): void
    {
        [$owner, $purchase] = $this->seedPublishedFixture(referenceStatus: LaboratoryResultReferenceStatus::High);

        $response = $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase));

        $glucose = collect($response->json('data.observations'))
            ->firstWhere('analyte.code', 'FAMEDIC_CHEM_GLU');

        $this->assertNotNull($glucose);
        $this->assertSame('high', $glucose['status']);
        $this->assertTrue($glucose['abnormal']);
        $this->assertEquals(70, $glucose['reference']['low']);
        $this->assertEquals(100, $glucose['reference']['high']);
        $this->assertSame('70-100', $glucose['reference']['text']);
    }

    #[Test]
    public function multiple_observations_are_returned_in_deterministic_order(): void
    {
        [$owner, $purchase, $report, $item] = $this->seedPublishedFixture(returnItem: true);

        LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $report->id,
            'laboratory_purchase_item_id' => $item->id,
            'panel_name_raw' => 'Quimica',
            'source_page' => 2,
            'analyte_name_raw' => 'Creatinina',
            'analyte_code' => 'CREAT',
            'numeric_value' => 1.1,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
            'abnormal_flag' => false,
        ]);

        $response = $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase));

        $response->assertOk()
            ->assertJsonCount(3, 'data.observations');

        $names = collect($response->json('data.observations'))->pluck('analyte.name')->all();
        $this->assertSame(['Hemoglobina', 'Glucosa', 'Creatinina'], $names);
    }

    #[Test]
    public function empty_published_result_returns_not_found(): void
    {
        [$owner, $purchase] = $this->seedPurchaseWithVersion();

        $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase))
            ->assertNotFound();
    }

    #[Test]
    public function version_isolation_returns_only_active_published_report(): void
    {
        [$owner, $purchase, $version] = $this->seedPurchaseWithVersion();

        $published = LaboratoryResultReport::factory()->published($version)->create([
            'input_hash' => hash('sha256', 'active-published'),
        ]);

        LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $published->id,
            'analyte_name_raw' => 'Activo',
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
        ]);

        $otherVersion = LaboratoryResultVersion::query()->create([
            'laboratory_result_status_id' => $version->laboratory_result_status_id,
            'storage_path' => 'results/gda-'.$purchase->id.'-other.pdf',
            'sha256' => hash('sha256', 'other-version'),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'explicit_complete_signal',
            'matched_rule' => 'test',
            'classifier' => 'deterministic_pdf_v1',
            'classified_at' => now(),
        ]);

        LaboratoryResultReport::factory()->forVersion($otherVersion)->create([
            'structured_status' => LaboratoryResultStructuredStatus::Draft,
            'input_hash' => hash('sha256', 'other-version-draft'),
        ]);

        $response = $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase));

        $response->assertOk()
            ->assertJsonPath('data.report.id', $published->id)
            ->assertJsonPath('data.report.version_id', $version->id)
            ->assertJsonPath('data.observations.0.analyte.name', 'Activo');
    }

    #[Test]
    public function structured_results_endpoint_makes_no_openai_calls(): void
    {
        [$owner, $purchase] = $this->seedPublishedFixture();

        $this->mock(OpenAiClient::class, function ($mock) {
            $mock->shouldNotReceive('chatCompletion');
            $mock->shouldNotReceive('chatCompletionWithMetadata');
        });

        $this->actingAs($owner)
            ->getJson(route('laboratory-purchases.structured-results', $purchase))
            ->assertOk();
    }

    #[Test]
    public function report_from_other_purchase_is_not_accessible_via_purchase_scoped_endpoint(): void
    {
        [, $purchaseA, $reportA] = $this->seedPublishedFixture();
        [$ownerB, $purchaseB] = $this->seedPublishedFixture();

        $this->assertNotSame($purchaseA->id, $purchaseB->id);
        $this->assertNotSame($reportA->laboratory_purchase_id, $purchaseB->id);

        $response = $this->actingAs($ownerB)
            ->getJson(route('laboratory-purchases.structured-results', $purchaseB));

        $response->assertOk()
            ->assertJsonPath('data.report.id', fn ($id) => $id !== $reportA->id);
    }

    private function withoutPatientGateMiddleware(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            \App\Http\Middleware\RedirectIfUserProfileIsIncomplete::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            \App\Http\Middleware\EnsurePhoneIsVerified::class,
            \App\Http\Middleware\EnsureUserHasCustomerAccount::class,
            \App\Http\Middleware\EnsureLabResultsOtpVerified::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
    }

    /**
     * @return array{0: User, 1: LaboratoryPurchase, 2: LaboratoryResultReport}
     */
    private function seedPublishedFixture(
        ?LaboratoryResultReferenceStatus $referenceStatus = null,
        bool $returnItem = false,
    ): array {
        [$owner, $purchase, $version, $item] = $this->seedPurchaseWithVersion(returnItem: true);

        $report = LaboratoryResultReport::factory()->published($version)->create([
            'reported_at' => now()->subDay(),
            'specimen_collected_at' => now()->subDays(2),
            'input_hash' => hash('sha256', 'published-'.uniqid()),
        ]);

        $analyte = LaboratoryAnalyte::query()->firstOrCreate(
            ['code' => 'FAMEDIC_CHEM_GLU'],
            [
                'canonical_name' => 'Glucosa',
                'default_unit' => 'mg/dL',
                'value_kind' => LaboratoryAnalyteValueKind::Numeric,
                'is_active' => true,
            ],
        );

        LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $report->id,
            'laboratory_analyte_id' => $analyte->id,
            'laboratory_purchase_item_id' => $item->id,
            'analyte_code' => $analyte->code,
            'analyte_name_raw' => 'Glucosa',
            'analyte_name_display' => 'Glucosa',
            'numeric_value' => 108,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'unit' => 'mg/dL',
            'reference_low' => 70,
            'reference_high' => 100,
            'reference_text' => '70-100',
            'reference_status' => $referenceStatus ?? LaboratoryResultReferenceStatus::High,
            'abnormal_flag' => ($referenceStatus ?? LaboratoryResultReferenceStatus::High) !== LaboratoryResultReferenceStatus::Normal,
            'panel_name_raw' => 'Quimica',
            'source_page' => 1,
            'metadata' => ['promotion_evaluation' => ['status' => 'validated']],
            'confidence' => 0.99,
        ]);

        LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $report->id,
            'analyte_name_raw' => 'Hemoglobina',
            'analyte_code' => 'HGB',
            'numeric_value' => 14.2,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
            'abnormal_flag' => false,
            'panel_name_raw' => 'Hematologia',
            'source_page' => 3,
        ]);

        if ($returnItem) {
            return [$owner, $purchase, $report, $item];
        }

        return [$owner, $purchase, $report];
    }

    /**
     * @return array{0: User, 1: LaboratoryPurchase, 2: LaboratoryResultVersion}|array{0: User, 1: LaboratoryPurchase, 2: LaboratoryResultVersion, 3: LaboratoryPurchaseItem}
     */
    private function seedPurchaseWithVersion(bool $returnItem = false): array
    {
        $user = User::query()->create([
            'name' => 'Paciente Test',
            'email' => 'patient-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
        ]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-'.fake()->unique()->numerify('#####'),
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'Structured',
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
            'gda_id' => 'GDA-1',
            'name' => 'Quimica sanguinea',
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
            'storage_path' => 'results/gda-'.$purchase->id.'-abc123.pdf',
            'sha256' => hash('sha256', 'pdf-binary-fixture'),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'explicit_complete_signal',
            'matched_rule' => 'test',
            'classifier' => 'deterministic_pdf_v1',
            'classified_at' => now(),
        ]);

        if ($returnItem) {
            return [$user, $purchase, $version, $item];
        }

        return [$user, $purchase, $version];
    }

    private function createOtherCustomerUser(): User
    {
        $user = User::query()->create([
            'name' => 'Otro Paciente',
            'email' => 'other-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        Customer::query()->create([
            'user_id' => $user->id,
        ]);

        return $user;
    }

}
