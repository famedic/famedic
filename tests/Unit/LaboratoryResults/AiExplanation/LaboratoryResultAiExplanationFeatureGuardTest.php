<?php

namespace Tests\Unit\LaboratoryResults\AiExplanation;

use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationFeatureGuard;
use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryResultAiExplanationFeatureGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
        parent::tearDown();
    }

    #[Test]
    public function production_cannot_enable_ai_explanation_even_when_flag_is_true(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['laboratory-results.ai_explanation.enabled' => true]);

        $guard = app(LaboratoryResultAiExplanationFeatureGuard::class);

        $this->assertFalse($guard->isEffectiveEnabled());
        $this->assertSame('production_blocked', $guard->blockReason());
        $this->assertTrue($guard->isConfigFlagEnabled());
        $this->assertTrue($guard->isProductionEnvironment());
    }

    #[Test]
    public function staging_allows_ai_explanation_when_flag_is_true(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        config(['laboratory-results.ai_explanation.enabled' => true]);

        $guard = app(LaboratoryResultAiExplanationFeatureGuard::class);

        $this->assertTrue($guard->isEffectiveEnabled());
        $this->assertNull($guard->blockReason());
    }

    #[Test]
    public function testing_allows_ai_explanation_when_flag_is_true(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
        config(['laboratory-results.ai_explanation.enabled' => true]);

        $guard = app(LaboratoryResultAiExplanationFeatureGuard::class);

        $this->assertTrue($guard->isEffectiveEnabled());
    }

    #[Test]
    public function disallowed_environment_blocks_even_when_flag_is_true(): void
    {
        $this->app->detectEnvironment(fn () => 'uat');
        config([
            'laboratory-results.ai_explanation.enabled' => true,
            'laboratory-results.ai_explanation.allowed_environments' => ['local', 'testing', 'qa', 'staging'],
        ]);

        $guard = app(LaboratoryResultAiExplanationFeatureGuard::class);

        $this->assertFalse($guard->isEffectiveEnabled());
        $this->assertSame('environment_not_allowed', $guard->blockReason());
    }

    #[Test]
    public function service_reports_feature_disabled_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['laboratory-results.ai_explanation.enabled' => true]);

        $service = app(LaboratoryResultAiExplanationService::class);

        $this->assertFalse($service->isEnabled());
    }
}
