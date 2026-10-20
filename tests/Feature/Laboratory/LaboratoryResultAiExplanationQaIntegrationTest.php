<?php

namespace Tests\Feature\Laboratory;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\LaboratoryResultAiExplanationStatus;
use App\Enums\LaboratoryResultObservationValueType;
use App\Enums\LaboratoryResultReferenceStatus;
use App\Enums\LaboratoryResultStructuredStatus;
use App\Jobs\GenerateLaboratoryResultAiExplanationJob;
use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationFeatureGuard;
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
use App\Services\LaboratoryResults\AiExplanation\Qa\LaboratoryResultAiExplanationObservationSnapshot;
use App\Services\LaboratoryResults\AiExplanation\Qa\LaboratoryResultAiExplanationQaCorpus;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultAiExplanationQaIntegrationTest extends TestCase
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
    public function ai_explanation_never_mutates_observation_on_ready_invalid_and_failed(): void
    {
        foreach ([
            'ready' => LaboratoryResultAiExplanationQaCorpus::safeResponses()['SAFE_002'],
            'invalid' => ['explanation' => 'Tienes diabetes.', 'limitations' => 'Consulta.'],
            'failed' => null,
        ] as $scenario => $content) {
            [, , $observation] = $this->seedPublishedObservation();
            $snapshotBefore = LaboratoryResultAiExplanationObservationSnapshot::capture($observation);

            $explanation = $this->createPendingExplanation($observation);

            if ($scenario === 'failed') {
                $this->mock(OpenAiClient::class, fn ($mock) => $mock
                    ->shouldReceive('chatCompletionWithMetadata')
                    ->once()
                    ->andThrow(new \RuntimeException('OpenAI timeout')));
                try {
                    app(LaboratoryResultAiExplanationGenerator::class)->generate($explanation);
                } catch (\RuntimeException) {
                }
            } else {
                $this->mock(OpenAiClient::class, fn ($mock) => $mock
                    ->shouldReceive('chatCompletionWithMetadata')
                    ->once()
                    ->andReturn([
                        'content' => $content,
                        'model' => 'gpt-4o-mini',
                        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
                    ]));
                app(LaboratoryResultAiExplanationGenerator::class)->generate($explanation);
            }

            $observation->refresh();
            $snapshotAfter = LaboratoryResultAiExplanationObservationSnapshot::capture($observation);

            $this->assertTrue(
                LaboratoryResultAiExplanationObservationSnapshot::assertUnchanged($snapshotBefore, $snapshotAfter),
                "Observation mutated after {$scenario}",
            );
        }
    }

    #[Test]
    public function safe_corpus_reaches_ready_via_generator(): void
    {
        foreach (LaboratoryResultAiExplanationQaCorpus::statusForSafeCase() as $caseId => $status) {
            [, , $observation] = $this->seedPublishedObservation($status);
            $explanation = $this->createPendingExplanation($observation);
            $output = LaboratoryResultAiExplanationQaCorpus::safeResponses()[$caseId];

            $this->mock(OpenAiClient::class, fn ($mock) => $mock
                ->shouldReceive('chatCompletionWithMetadata')
                ->once()
                ->andReturn([
                    'content' => $output,
                    'model' => 'gpt-4o-mini',
                    'usage' => [],
                ]));

            app(LaboratoryResultAiExplanationGenerator::class)->generate($explanation);

            $explanation->refresh();
            $this->assertSame(LaboratoryResultAiExplanationStatus::Ready, $explanation->status, $caseId);
        }
    }

    #[Test]
    public function explanation_is_isolated_to_target_observation(): void
    {
        [$owner, $purchase, $observationA] = $this->seedPublishedObservation();
        [, , $observationB] = $this->seedPublishedObservation();
        $this->acceptConsent($owner);

        $snapshotBBefore = LaboratoryResultAiExplanationObservationSnapshot::capture($observationB);

        $explanation = $this->createPendingExplanation($observationA);
        $output = LaboratoryResultAiExplanationQaCorpus::safeResponses()['SAFE_002'];

        $this->mock(OpenAiClient::class, fn ($mock) => $mock
            ->shouldReceive('chatCompletionWithMetadata')
            ->once()
            ->andReturn(['content' => $output, 'model' => 'gpt-4o-mini', 'usage' => []]));

        app(LaboratoryResultAiExplanationGenerator::class)->generate($explanation);

        $observationB->refresh();
        $this->assertTrue(
            LaboratoryResultAiExplanationObservationSnapshot::assertUnchanged(
                $snapshotBBefore,
                LaboratoryResultAiExplanationObservationSnapshot::capture($observationB),
            ),
        );

        $this->assertSame($observationA->id, $explanation->fresh()->laboratory_result_observation_id);
        $this->assertDatabaseMissing('laboratory_result_ai_explanations', [
            'laboratory_result_observation_id' => $observationB->id,
            'status' => LaboratoryResultAiExplanationStatus::Ready->value,
        ]);
    }

    #[Test]
    public function idempotency_reuses_ready_without_second_openai_call(): void
    {
        Bus::fake();
        [$owner, $purchase, $observation] = $this->seedPublishedObservation();
        $this->acceptConsent($owner);

        $input = (new LaboratoryResultAiExplanationInputBuilder())->fromObservation($observation);
        $hash = LaboratoryResultAiExplanationInputHash::compute($input, LaboratoryResultAiExplanationContract::PROMPT_VERSION);

        LaboratoryResultAiExplanation::query()->create([
            'laboratory_result_observation_id' => $observation->id,
            'status' => LaboratoryResultAiExplanationStatus::Ready,
            'explanation' => 'Cached.',
            'limitations' => 'Cached limitations.',
            'input_hash' => $hash,
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
            'generated_at' => now(),
        ]);

        $this->mock(OpenAiClient::class, fn ($mock) => $mock->shouldNotReceive('chatCompletionWithMetadata'));

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $purchase->id,
                'observation' => $observation->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function idempotency_new_hash_dispatches_new_explanation(): void
    {
        Bus::fake();
        [$owner, $purchase, $observation] = $this->seedPublishedObservation();
        $this->acceptConsent($owner);

        $input = (new LaboratoryResultAiExplanationInputBuilder())->fromObservation($observation);
        $hash = LaboratoryResultAiExplanationInputHash::compute($input, LaboratoryResultAiExplanationContract::PROMPT_VERSION);

        LaboratoryResultAiExplanation::query()->create([
            'laboratory_result_observation_id' => $observation->id,
            'status' => LaboratoryResultAiExplanationStatus::Ready,
            'explanation' => 'Old.',
            'limitations' => 'Old.',
            'input_hash' => $hash,
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
            'generated_at' => now(),
        ]);

        $observation->update(['numeric_value' => 109]);

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
    public function prompt_version_change_produces_different_hash(): void
    {
        $input = LaboratoryResultAiExplanationQaCorpus::highGlucoseInput();
        $hashV1 = LaboratoryResultAiExplanationInputHash::compute($input, 1);
        $hashV2 = LaboratoryResultAiExplanationInputHash::compute($input, 2);

        $this->assertNotSame($hashV1, $hashV2);
    }

    #[Test]
    public function job_retry_eventually_reaches_ready_after_transient_failures(): void
    {
        [, , $observation] = $this->seedPublishedObservation();
        $explanation = $this->createPendingExplanation($observation);
        $output = LaboratoryResultAiExplanationQaCorpus::safeResponses()['SAFE_002'];
        $attempt = 0;

        $this->mock(OpenAiClient::class, function ($mock) use (&$attempt, $output) {
            $mock->shouldReceive('chatCompletionWithMetadata')
                ->times(3)
                ->andReturnUsing(function () use (&$attempt, $output) {
                    $attempt++;
                    if ($attempt < 3) {
                        throw new \RuntimeException('OpenAI timeout');
                    }

                    return ['content' => $output, 'model' => 'gpt-4o-mini', 'usage' => []];
                });
        });

        $job = new GenerateLaboratoryResultAiExplanationJob($explanation->id);

        try {
            $this->runAiExplanationJob($job);
        } catch (\RuntimeException) {
        }

        try {
            $this->runAiExplanationJob($job);
        } catch (\RuntimeException) {
        }

        $this->runAiExplanationJob($job);

        $explanation->refresh();
        $this->assertSame(LaboratoryResultAiExplanationStatus::Ready, $explanation->status);
    }

    #[Test]
    public function three_consecutive_failures_leave_failed_status_and_intact_observation(): void
    {
        [, , $observation] = $this->seedPublishedObservation();
        $snapshotBefore = LaboratoryResultAiExplanationObservationSnapshot::capture($observation);
        $explanation = $this->createPendingExplanation($observation);

        $this->mock(OpenAiClient::class, fn ($mock) => $mock
            ->shouldReceive('chatCompletionWithMetadata')
            ->times(3)
            ->andThrow(new \RuntimeException('OpenAI 429')));

        $job = new GenerateLaboratoryResultAiExplanationJob($explanation->id);

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->runAiExplanationJob($job);
            } catch (\RuntimeException) {
            }
        }

        $explanation->refresh();
        $observation->refresh();

        $this->assertSame(LaboratoryResultAiExplanationStatus::Failed, $explanation->status);
        $this->assertNotSame(LaboratoryResultAiExplanationStatus::Generating, $explanation->status);
        $this->assertTrue(
            LaboratoryResultAiExplanationObservationSnapshot::assertUnchanged(
                $snapshotBefore,
                LaboratoryResultAiExplanationObservationSnapshot::capture($observation),
            ),
        );
    }

    #[Test]
    public function ai_execution_is_pii_safe_and_metadata_correct(): void
    {
        [, , $observation] = $this->seedPublishedObservation();
        $explanation = $this->createPendingExplanation($observation);
        $output = LaboratoryResultAiExplanationQaCorpus::safeResponses()['SAFE_002'];

        $this->mock(OpenAiClient::class, fn ($mock) => $mock
            ->shouldReceive('chatCompletionWithMetadata')
            ->once()
            ->andReturn(['content' => $output, 'model' => 'gpt-4o-mini', 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 10, 'total_tokens' => 15]]));

        app(LaboratoryResultAiExplanationGenerator::class)->generate($explanation);

        $execution = AiExecution::query()->where('feature', LaboratoryResultAiExplanationContract::FEATURE)->first();
        $this->assertNotNull($execution);
        $this->assertSame(LaboratoryResultAiExplanationContract::DOMAIN, $execution->domain);
        $this->assertSame($observation->id, $execution->subject_id);
        $this->assertSame($explanation->input_hash, $execution->input_hash);
        $this->assertSame(LaboratoryResultAiExplanationContract::PROMPT_VERSION, $execution->prompt_version);

        $payload = json_encode($execution->request_payload_redacted);
        foreach (['customer_id', 'email', 'phone', 'pdf', 'base64', 'raw_extraction_payload', 'patient_name'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload ?: '');
        }
    }

    #[Test]
    public function feature_flag_disabled_never_dispatches_or_calls_openai(): void
    {
        config(['laboratory-results.ai_explanation.enabled' => false]);
        Bus::fake();

        [$owner, $purchase, $observation] = $this->seedPublishedObservation();
        $this->acceptConsent($owner);

        $this->mock(OpenAiClient::class, fn ($mock) => $mock->shouldNotReceive('chatCompletionWithMetadata'));

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $purchase->id,
                'observation' => $observation->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'feature_disabled');

        Bus::assertNothingDispatched();
        $this->assertDatabaseCount('laboratory_result_ai_explanations', 0);
    }

    #[Test]
    public function shadow_and_draft_reports_are_rejected(): void
    {
        Bus::fake();
        [$owner, $purchase, $version] = $this->seedPurchaseWithVersion();
        $this->acceptConsent($owner);

        $shadowReport = LaboratoryResultReport::factory()->forVersion($version)->create([
            'structured_status' => LaboratoryResultStructuredStatus::Validated,
            'input_hash' => hash('sha256', 'shadow'),
            'raw_extraction_payload' => ['shadow_qa' => true],
            'extraction_method' => \App\Enums\LaboratoryResultExtractionMethod::Vision,
        ]);

        $shadowObservation = LaboratoryResultObservation::factory()->create([
            'laboratory_result_report_id' => $shadowReport->id,
            'reference_status' => LaboratoryResultReferenceStatus::Normal,
        ]);

        $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.structured-results.explanation', [
                'laboratory_purchase' => $purchase->id,
                'observation' => $shadowObservation->id,
            ]))
            ->assertNotFound();

        Bus::assertNothingDispatched();
    }

    private function runAiExplanationJob(GenerateLaboratoryResultAiExplanationJob $job): void
    {
        $job->handle(
            app(LaboratoryResultAiExplanationGenerator::class),
            app(LaboratoryResultAiExplanationFeatureGuard::class),
        );
    }

    private function createPendingExplanation(LaboratoryResultObservation $observation): LaboratoryResultAiExplanation
    {
        $input = (new LaboratoryResultAiExplanationInputBuilder())->fromObservation($observation);
        $hash = LaboratoryResultAiExplanationInputHash::compute($input, LaboratoryResultAiExplanationContract::PROMPT_VERSION);

        return LaboratoryResultAiExplanation::query()->create([
            'laboratory_result_observation_id' => $observation->id,
            'status' => LaboratoryResultAiExplanationStatus::Pending,
            'input_hash' => $hash,
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
        ]);
    }

    /** @return array{0: User, 1: LaboratoryPurchase, 2: LaboratoryResultObservation} */
    private function seedPublishedObservation(
        string $status = 'high',
    ): array {
        [$user, $purchase, $version] = $this->seedPurchaseWithVersion();

        $report = LaboratoryResultReport::factory()->published($version)->create([
            'input_hash' => hash('sha256', 'published-'.uniqid()),
        ]);

        $referenceStatus = match ($status) {
            'normal' => LaboratoryResultReferenceStatus::Normal,
            'low' => LaboratoryResultReferenceStatus::Low,
            'unknown' => LaboratoryResultReferenceStatus::Unknown,
            'not_applicable' => LaboratoryResultReferenceStatus::NotApplicable,
            default => LaboratoryResultReferenceStatus::High,
        };

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
            'reference_status' => $referenceStatus,
            'abnormal_flag' => ! in_array($status, ['normal', 'unknown', 'not_applicable'], true),
        ]);

        return [$user, $purchase, $observation];
    }

    /** @return array{0: User, 1: LaboratoryPurchase, 2: LaboratoryResultVersion} */
    private function seedPurchaseWithVersion(): array
    {
        $user = User::query()->create([
            'name' => 'Paciente QA',
            'email' => 'patient-qa-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        Customer::query()->create(['user_id' => $user->id]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $user->customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'ORD-'.fake()->unique()->numerify('#####'),
            'name' => 'Test',
            'paternal_lastname' => 'Patient',
            'maternal_lastname' => 'QA',
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
            'sha256' => hash('sha256', 'pdf-'.$purchase->id),
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
                'status' => \App\Enums\CustomerLaboratoryAiExplanationConsentStatus::Accepted,
                'consent_version' => LaboratoryResultAiExplanationContract::CONSENT_VERSION,
                'consented_at' => now(),
                'declined_at' => null,
            ],
        );
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
