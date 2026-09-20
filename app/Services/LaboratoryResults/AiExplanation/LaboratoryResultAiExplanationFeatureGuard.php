<?php

namespace App\Services\LaboratoryResults\AiExplanation;

final class LaboratoryResultAiExplanationFeatureGuard
{
    public function isConfigFlagEnabled(): bool
    {
        return (bool) config('laboratory-results.ai_explanation.enabled', false);
    }

    public function isProductionEnvironment(): bool
    {
        return app()->isProduction();
    }

    public function isAllowedEnvironment(): bool
    {
        $allowed = config('laboratory-results.ai_explanation.allowed_environments', ['local', 'testing', 'qa', 'staging']);

        return in_array(app()->environment(), $allowed, true);
    }

    /**
     * Effective enablement: flag ON + non-production + allowed environment.
     * Production is always blocked, even if LAB_RESULTS_AI_EXPLANATION_ENABLED=true.
     */
    public function isEffectiveEnabled(): bool
    {
        if (! $this->isConfigFlagEnabled()) {
            return false;
        }

        if ($this->isProductionEnvironment()) {
            return false;
        }

        return $this->isAllowedEnvironment();
    }

    public function blockReason(): ?string
    {
        if (! $this->isConfigFlagEnabled()) {
            return 'flag_disabled';
        }

        if ($this->isProductionEnvironment()) {
            return 'production_blocked';
        }

        if (! $this->isAllowedEnvironment()) {
            return 'environment_not_allowed';
        }

        return null;
    }
}
