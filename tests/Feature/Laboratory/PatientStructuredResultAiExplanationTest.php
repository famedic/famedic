<?php

namespace Tests\Feature\Laboratory;

use App\Enums\CustomerLaboratoryAiExplanationConsentStatus;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultAiExplanationStatus;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Jobs\GenerateLaboratoryResultAiExplanationJob;
use App\Models\AiExecution;
use App\Models\Customer;
use App\Models\CustomerLaboratoryAiExplanationConsent;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryResultAiExplanation;
use App\Models\LaboratoryResultObservation;
use App\Models\LaboratoryResultReport;
use App\Models\LaboratoryResultStatus;
use App\Models\LaboratoryResultVersion;
use App\Models\User;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationContract;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationInputBuilder;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationInputHash;
use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationGenerator;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PatientStructuredResultAiExplanationTest extends TestCase
{
    use AiExplanationIsolatedSchema;
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        config([
            'laboratory-results.otp_required' => false,
            'laboratory-results.ai_explanation.enabled' => true,
            'laboratory-results.ai_explanation.consent_required' => true,
        ]);

        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
        $this->bootstrapAiExplanationSchema();
        $this->withoutPatientGateMiddleware();
    }

    protected function tearDown(): void
    {
        $this->tearDownAiExplanationSchema();
        $this->tearDownStructuredResultsSchema();
        $this->tearDownIsolatedSchema();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function without_consent_endpoint_returns_consent_required_and_does_not_dispatch_job(): void
    {
        Bus::fake();
        [$owner, $purchase, $observation] = $this->seedPublishedObservation();

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $purchase->id,
                'observation' => $observation->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'consent_required');

        Bus::assertNothingDispatched();
        $this->mock(OpenAiClient::class, fn ($mock) => $mock->shouldNotReceive('chatCompletionWithMetadata'));
    }

    #[Test]
    public function declined_consent_does_not_dispatch_job(): void
    {
        Bus::fake();
        [$owner, $purchase, $observation] = $this->seedPublishedObservation();
        $this->declineConsent($owner);

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $purchase->id,
                'observation' => $observation->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'consent_required');

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function accepted_consent_dispatches_job_and_returns_pending(): void
    {
        Bus::fake();
        [$owner, $purchase, $observation] = $this->seedPublishedObservation();
        $this->acceptConsent($owner);

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $purchase->id,
                'observation' => $observation->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        Bus::assertDispatched(GenerateLaboratoryResultAiExplanationJob::class);
    }

    #[Test]
    public function feature_flag_disabled_returns_feature_disabled_without_openai(): void
    {
        config(['laboratory-results.ai_explanation.enabled' => false]);
        Bus::fake();
        [$owner, $purchase, $observation] = $this->seedPublishedObservation();
        $this->acceptConsent($owner);

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $purchase->id,
                'observation' => $observation->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'feature_disabled');

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function cross_customer_is_forbidden(): void
    {
        [, $purchase, $observation] = $this->seedPublishedObservation();
        $other = $this->createOtherCustomerUser();

        $this->actingAs($other)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $purchase->id,
                'observation' => $observation->id,
            ]))
            ->assertForbidden();
    }

    #[Test]
    public function observation_from_unpublished_report_is_not_found(): void
    {
        [$owner, $purchase, $version] = $this->seedPurchaseWithVersion();
        $report = LaboratoryResultReport::factory()->forVersion($version)->create([
            'structured_status' => LaboratoryResultStructuredStatus::Draft,
            'input_hash' => hash('sha256', 'draft-report'),
        ]);
        $observation = LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $report->id,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
        ]);
        $this->acceptConsent($owner);

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $purchase->id,
                'observation' => $observation->id,
            ]))
            ->assertNotFound();
    }

    #[Test]
    public function ready_explanation_is_reused_without_dispatching_job(): void
    {
        Bus::fake();
        [$owner, $purchase, $observation] = $this->seedPublishedObservation();
        $this->acceptConsent($owner);

        $input = (new LaboratoryResultAiExplanationInputBuilder())->fromObservation($observation);
        $hash = LaboratoryResultAiExplanationInputHash::compute($input, LaboratoryResultAiExplanationContract::PROMPT_VERSION);

        LaboratoryResultAiExplanation::query()->create([
            'laboratory_result_observation_id' => $observation->id,
            'status' => LaboratoryResultAiExplanationStatus::Ready,
            'explanation' => 'Explicación existente.',
            'limitations' => 'Limitación existente.',
            'input_hash' => $hash,
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
            'generated_at' => now(),
        ]);

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $purchase->id,
                'observation' => $observation->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.explanation', 'Explicación existente.');

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function generator_marks_ready_and_preserves_observation_clinical_fields(): void
    {
        [$owner, $purchase, $observation] = $this->seedPublishedObservation();
        $this->acceptConsent($owner);

        $input = (new LaboratoryResultAiExplanationInputBuilder())->fromObservation($observation);
        $hash = LaboratoryResultAiExplanationInputHash::compute($input, LaboratoryResultAiExplanationContract::PROMPT_VERSION);

        $explanation = LaboratoryResultAiExplanation::query()->create([
            'laboratory_result_observation_id' => $observation->id,
            'status' => LaboratoryResultAiExplanationStatus::Pending,
            'input_hash' => $hash,
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
        ]);

        $before = [
            'reference_status' => $observation->reference_status?->value,
            'abnormal_flag' => $observation->abnormal_flag,
            'numeric_value' => (string) $observation->numeric_value,
        ];

        $this->mock(OpenAiClient::class, function ($mock) {
            $mock->shouldReceive('chatCompletionWithMetadata')
                ->once()
                ->andReturn([
                    'content' => [
                        'explanation' => 'El resultado está por encima del rango de referencia indicado por el laboratorio.',
                        'limitations' => 'Esta información es orientativa y no sustituye la valoración de un profesional de la salud.',
                    ],
                    'model' => 'gpt-4o-mini',
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
                ]);
        });

        app(LaboratoryResultAiExplanationGenerator::class)->generate($explanation);

        $observation->refresh();
        $explanation->refresh();

        $this->assertSame(LaboratoryResultAiExplanationStatus::Ready, $explanation->status);
        $this->assertSame($before['reference_status'], $observation->reference_status?->value);
        $this->assertSame($before['abnormal_flag'], $observation->abnormal_flag);
        $this->assertSame($before['numeric_value'], (string) $observation->numeric_value);

        $execution = AiExecution::query()->where('feature', LaboratoryResultAiExplanationContract::FEATURE)->first();
        $this->assertNotNull($execution);
        $this->assertSame($hash, $execution->input_hash);
        $this->assertArrayNotHasKey('customer_id', $execution->request_payload_redacted ?? []);
    }

    #[Test]
    public function invalid_openai_output_marks_invalid_without_modifying_observation(): void
    {
        [$owner, $purchase, $observation] = $this->seedPublishedObservation();

        $input = (new LaboratoryResultAiExplanationInputBuilder())->fromObservation($observation);
        $hash = LaboratoryResultAiExplanationInputHash::compute($input, LaboratoryResultAiExplanationContract::PROMPT_VERSION);

        $explanation = LaboratoryResultAiExplanation::query()->create([
            'laboratory_result_observation_id' => $observation->id,
            'status' => LaboratoryResultAiExplanationStatus::Pending,
            'input_hash' => $hash,
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
        ]);

        $beforeStatus = $observation->reference_status;

        $this->mock(OpenAiClient::class, function ($mock) {
            $mock->shouldReceive('chatCompletionWithMetadata')
                ->once()
                ->andReturn([
                    'content' => [
                        'explanation' => 'Tienes diabetes.',
                        'limitations' => 'Debes tomar medicamento.',
                    ],
                    'model' => 'gpt-4o-mini',
                    'usage' => [],
                ]);
        });

        app(LaboratoryResultAiExplanationGenerator::class)->generate($explanation);

        $observation->refresh();
        $explanation->refresh();

        $this->assertSame(LaboratoryResultAiExplanationStatus::Invalid, $explanation->status);
        $this->assertSame($beforeStatus, $observation->reference_status);
    }

    #[Test]
    public function guest_cannot_request_explanation(): void
    {
        [, $purchase, $observation] = $this->seedPublishedObservation();

        $this->postJson(route('laboratory-purchases.structured-results.explanation', [
            'laboratory_purchase' => $purchase->id,
            'observation' => $observation->id,
        ]))
            ->assertUnauthorized();
    }

    #[Test]
    public function observation_from_other_customers_purchase_is_forbidden(): void
    {
        [$owner, , $observation] = $this->seedPublishedObservation();
        [, $otherPurchase] = $this->seedPurchaseWithVersion();
        $this->acceptConsent($owner);

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $otherPurchase->id,
                'observation' => $observation->id,
            ]))
            ->assertForbidden();
    }

    #[Test]
    public function observation_from_different_purchase_of_same_customer_is_not_found(): void
    {
        [$owner, $purchase, $observation] = $this->seedPublishedObservation();
        [, $otherPurchase] = $this->seedPurchaseWithVersionForCustomer($owner);
        $this->acceptConsent($owner);

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $otherPurchase->id,
                'observation' => $observation->id,
            ]))
            ->assertNotFound();
    }

    #[Test]
    public function changed_observation_value_produces_new_explanation_request(): void
    {
        Bus::fake();
        [$owner, $purchase, $observation] = $this->seedPublishedObservation();
        $this->acceptConsent($owner);

        $input = (new LaboratoryResultAiExplanationInputBuilder())->fromObservation($observation);
        $hash = LaboratoryResultAiExplanationInputHash::compute($input, LaboratoryResultAiExplanationContract::PROMPT_VERSION);

        LaboratoryResultAiExplanation::query()->create([
            'laboratory_result_observation_id' => $observation->id,
            'status' => LaboratoryResultAiExplanationStatus::Ready,
            'explanation' => 'Explicación anterior.',
            'limitations' => 'Limitación anterior.',
            'input_hash' => $hash,
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
            'generated_at' => now(),
        ]);

        $observation->update(['numeric_value' => 120]);

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $purchase->id,
                'observation' => $observation->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        Bus::assertDispatched(GenerateLaboratoryResultAiExplanationJob::class);
    }

    #[Test]
    public function openai_failure_marks_explanation_failed_without_modifying_observation(): void
    {
        [, , $observation] = $this->seedPublishedObservation();

        $input = (new LaboratoryResultAiExplanationInputBuilder())->fromObservation($observation);
        $hash = LaboratoryResultAiExplanationInputHash::compute($input, LaboratoryResultAiExplanationContract::PROMPT_VERSION);

        $explanation = LaboratoryResultAiExplanation::query()->create([
            'laboratory_result_observation_id' => $observation->id,
            'status' => LaboratoryResultAiExplanationStatus::Pending,
            'input_hash' => $hash,
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
        ]);

        $beforeStatus = $observation->reference_status;

        $this->mock(OpenAiClient::class, function ($mock) {
            $mock->shouldReceive('chatCompletionWithMetadata')
                ->once()
                ->andThrow(new \RuntimeException('OpenAI timeout'));
        });

        try {
            app(LaboratoryResultAiExplanationGenerator::class)->generate($explanation);
        } catch (\RuntimeException) {
            // markFailed rethrows for job retries.
        }

        $observation->refresh();
        $explanation->refresh();

        $this->assertSame(LaboratoryResultAiExplanationStatus::Failed, $explanation->status);
        $this->assertSame($beforeStatus, $observation->reference_status);
    }

    #[Test]
    public function openai_payload_never_contains_pii_keys(): void
    {
        [, , $observation] = $this->seedPublishedObservation();

        $input = (new LaboratoryResultAiExplanationInputBuilder())->fromObservation($observation);
        $hash = LaboratoryResultAiExplanationInputHash::compute($input, LaboratoryResultAiExplanationContract::PROMPT_VERSION);

        $explanation = LaboratoryResultAiExplanation::query()->create([
            'laboratory_result_observation_id' => $observation->id,
            'status' => LaboratoryResultAiExplanationStatus::Pending,
            'input_hash' => $hash,
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
        ]);

        $this->mock(OpenAiClient::class, function ($mock) use ($input) {
            $mock->shouldReceive('chatCompletionWithMetadata')
                ->once()
                ->withArgs(function ($messages) use ($input) {
                    $encoded = json_encode($messages);
                    foreach (LaboratoryResultAiExplanationContract::INPUT_FORBIDDEN_KEYS as $forbidden) {
                        if (str_contains($encoded, $forbidden)) {
                            return false;
                        }
                    }

                    return str_contains($encoded, (string) data_get($input, 'analyte.code'));
                })
                ->andReturn([
                    'content' => [
                        'explanation' => 'El valor está por encima del rango de referencia indicado.',
                        'limitations' => 'Información orientativa; no sustituye valoración profesional.',
                    ],
                    'model' => 'gpt-4o-mini',
                    'usage' => [],
                ]);
        });

        app(LaboratoryResultAiExplanationGenerator::class)->generate($explanation);
    }

    #[Test]
    public function consent_accept_endpoint_persists_customer_consent(): void
    {
        [$owner, $purchase] = $this->seedPublishedObservation();

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.ai-explanation-consent', $purchase), [
                'action' => 'accept',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', CustomerLaboratoryAiExplanationConsentStatus::Accepted->value);

        $this->assertDatabaseHas('customer_laboratory_ai_explanation_consents', [
            'customer_id' => $owner->customer->id,
            'status' => CustomerLaboratoryAiExplanationConsentStatus::Accepted->value,
            'consent_version' => LaboratoryResultAiExplanationContract::CONSENT_VERSION,
        ]);
    }

    /** @return array{0: User, 1: LaboratoryPurchase, 2: LaboratoryResultObservation} */
    private function seedPublishedObservation(): array
    {
        [$user, $purchase, $version] = $this->seedPurchaseWithVersion();

        $report = LaboratoryResultReport::factory()->published($version)->create([
            'input_hash' => hash('sha256', 'published-'.uniqid()),
        ]);

        $observation = LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $report->id,
            'analyte_code' => 'FAMEDIC_CHEM_GLU',
            'analyte_name_raw' => 'Glucosa',
            'numeric_value' => 108,
            'value_type' => LaboratoryResultObservationValueType::Numeric,
            'unit' => 'mg/dL',
            'reference_text' => '70-100',
            'reference_low' => 70,
            'reference_high' => 100,
            'reference_status' => LaboratoryResultReferenceStatus::High,
            'abnormal_flag' => true,
        ]);

        return [$user, $purchase, $observation];
    }

    /** @return array{0: LaboratoryPurchase, 1: LaboratoryResultVersion} */
    private function seedPurchaseWithVersionForCustomer(User $user): array
    {
        $customer = $user->customer ?? Customer::query()->where('user_id', $user->id)->firstOrFail();

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-'.fake()->unique()->numerify('#####'),
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'AI',
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
            'gda_id' => 'GDA-2',
            'name' => 'Quimica',
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
            'storage_path' => 'results/gda-'.$purchase->id.'.pdf',
            'sha256' => hash('sha256', 'pdf-'.$purchase->id),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'test',
            'matched_rule' => 'test',
            'classifier' => 'test',
            'classified_at' => now(),
        ]);

        return [$purchase, $version];
    }

    /** @return array{0: User, 1: LaboratoryPurchase, 2: LaboratoryResultVersion} */
    private function seedPurchaseWithVersion(): array
    {
        $user = User::query()->create([
            'name' => 'Paciente Test',
            'email' => 'patient-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $customer = Customer::query()->create(['user_id' => $user->id]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-'.fake()->unique()->numerify('#####'),
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'AI',
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
            'name' => 'Quimica',
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
            'storage_path' => 'results/gda-'.$purchase->id.'.pdf',
            'sha256' => hash('sha256', 'pdf'),
            'source' => 'gda',
            'classification' => 'complete',
            'classification_reason' => 'test',
            'matched_rule' => 'test',
            'classifier' => 'test',
            'classified_at' => now(),
        ]);

        return [$user, $purchase, $version];
    }

    private function acceptConsent(User $user): void
    {
        CustomerLaboratoryAiExplanationConsent::query()->updateOrCreate(
            ['customer_id' => $user->customer->id],
            [
                'user_id' => $user->id,
                'status' => CustomerLaboratoryAiExplanationConsentStatus::Accepted,
                'consent_version' => LaboratoryResultAiExplanationContract::CONSENT_VERSION,
                'consented_at' => now(),
                'declined_at' => null,
            ],
        );
    }

    private function declineConsent(User $user): void
    {
        CustomerLaboratoryAiExplanationConsent::query()->updateOrCreate(
            ['customer_id' => $user->customer->id],
            [
                'user_id' => $user->id,
                'status' => CustomerLaboratoryAiExplanationConsentStatus::Declined,
                'consent_version' => LaboratoryResultAiExplanationContract::CONSENT_VERSION,
                'consented_at' => null,
                'declined_at' => now(),
            ],
        );
    }

    private function createOtherCustomerUser(): User
    {
        $user = User::query()->create([
            'name' => 'Otro',
            'email' => 'other-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);
        Customer::query()->create(['user_id' => $user->id]);

        return $user;
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
        ]);
    }
}
