<?php

namespace Tests\Feature\Laboratory;

use App\Enums\CustomerLaboratoryAiExplanationConsentStatus;
use App\Enums\LaboratoryResultAiExplanationStatus;
use App\Models\AiExecution;
use App\Models\Customer;
use App\Models\CustomerLaboratoryAiExplanationConsent;
use App\Models\LaboratoryResultAiExplanation;
use App\Models\User;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationContract;
use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationObservabilityService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultAiExplanationObservabilityTest extends TestCase
{
    use AiExplanationIsolatedSchema;
    use GdaResultsStorageIsolatedSchema;
    use StructuredResultsIsolatedSchema;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'qa');
        $this->bootstrapIsolatedSchema();
        $this->bootstrapStructuredResultsSchema();
        $this->bootstrapAiExplanationSchema();
    }

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
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
    public function observability_report_aggregates_status_consent_and_execution_metrics(): void
    {
        $user = User::query()->create([
            'name' => 'QA Metrics',
            'email' => 'metrics-'.uniqid().'@test.local',
            'password' => bcrypt('secret'),
        ]);

        $customer = Customer::query()->create(['user_id' => $user->id]);

        CustomerLaboratoryAiExplanationConsent::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'status' => CustomerLaboratoryAiExplanationConsentStatus::Accepted,
            'consent_version' => LaboratoryResultAiExplanationContract::CONSENT_VERSION,
            'consented_at' => now(),
        ]);

        LaboratoryResultAiExplanation::query()->create([
            'laboratory_result_observation_id' => 1,
            'status' => LaboratoryResultAiExplanationStatus::Ready,
            'explanation' => 'Ready text',
            'limitations' => 'Limits',
            'input_hash' => hash('sha256', 'ready'),
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
            'generated_at' => now(),
        ]);

        LaboratoryResultAiExplanation::query()->create([
            'laboratory_result_observation_id' => 2,
            'status' => LaboratoryResultAiExplanationStatus::Invalid,
            'input_hash' => hash('sha256', 'invalid'),
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
        ]);

        AiExecution::query()->create([
            'domain' => LaboratoryResultAiExplanationContract::DOMAIN,
            'feature' => LaboratoryResultAiExplanationContract::FEATURE,
            'subject_type' => 'laboratory_result_observation',
            'subject_id' => 1,
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
            'model' => 'gpt-4o-mini',
            'status' => AiExecution::STATUS_SUCCEEDED,
            'input_hash' => hash('sha256', 'ready'),
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'duration_ms' => 1200,
        ]);

        AiExecution::query()->create([
            'domain' => LaboratoryResultAiExplanationContract::DOMAIN,
            'feature' => LaboratoryResultAiExplanationContract::FEATURE,
            'subject_type' => 'laboratory_result_observation',
            'subject_id' => 2,
            'prompt_version' => LaboratoryResultAiExplanationContract::PROMPT_VERSION,
            'model' => 'gpt-4o-mini',
            'status' => AiExecution::STATUS_FAILED,
            'error' => 'AI explanation output invalid: prohibited clinical language detected',
        ]);

        $report = app(LaboratoryResultAiExplanationObservabilityService::class)->buildReport();

        $this->assertSame(1, $report['explanations']['by_status']['ready']);
        $this->assertSame(1, $report['explanations']['by_status']['invalid']);
        $this->assertSame(1, $report['consent']['accepted']);
        $this->assertSame(150, $report['ai_execution']['avg_total_tokens']);
        $this->assertSame(1, $report['safety_failures']['safety_validation_failure']);
        $this->assertFalse($report['frontend_telemetry']['available']);
    }

    #[Test]
    public function observability_cannot_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);
        app(LaboratoryResultAiExplanationObservabilityService::class)->buildReport();
    }
}
