<?php

namespace App\Services\LaboratoryResults\AiExplanation;

use App\Enums\CustomerLaboratoryAiExplanationConsentStatus;
use App\Enums\LaboratoryResultAiExplanationStatus;
use App\Models\AiExecution;
use App\Models\CustomerLaboratoryAiExplanationConsent;
use App\Models\LaboratoryResultAiExplanation;
use App\Services\LaboratoryResults\AiExplanation\Contract\LaboratoryResultAiExplanationContract;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LaboratoryResultAiExplanationObservabilityService
{
    public function assertAllowedEnvironment(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('AI explanation observability cannot run in production.');
        }
    }

    /** @return array<string, mixed> */
    public function buildReport(?CarbonInterface $since = null): array
    {
        $this->assertAllowedEnvironment();

        $explanations = $this->explanationQuery($since);
        $executions = $this->executionQuery($since);
        $consents = $this->consentQuery($since);

        $statusCounts = $this->countByStatus($explanations);
        $consentCounts = $this->countConsents($consents);
        $executionMetrics = $this->summarizeExecutions($executions);
        $latency = $this->summarizeLatency($explanations);
        $safety = $this->summarizeSafetyFailures($executions);
        $promptConsistency = $this->summarizePromptConsistency($executions);

        $ready = (int) ($statusCounts[LaboratoryResultAiExplanationStatus::Ready->value] ?? 0);
        $failed = (int) ($statusCounts[LaboratoryResultAiExplanationStatus::Failed->value] ?? 0);
        $invalid = (int) ($statusCounts[LaboratoryResultAiExplanationStatus::Invalid->value] ?? 0);
        $terminal = max(1, $ready + $failed + $invalid);

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'feature' => [
                'config_flag_enabled' => app(LaboratoryResultAiExplanationFeatureGuard::class)->isConfigFlagEnabled(),
                'effective_enabled' => app(LaboratoryResultAiExplanationFeatureGuard::class)->isEffectiveEnabled(),
                'block_reason' => app(LaboratoryResultAiExplanationFeatureGuard::class)->blockReason(),
            ],
            'since' => $since?->toIso8601String(),
            'explanations' => [
                'total' => $explanations->count(),
                'by_status' => $statusCounts,
                'ready_rate' => round($ready / $terminal, 4),
                'failed_rate' => round($failed / $terminal, 4),
                'invalid_rate' => round($invalid / $terminal, 4),
            ],
            'consent' => $consentCounts,
            'latency_ms' => $latency,
            'ai_execution' => $executionMetrics,
            'safety_failures' => $safety,
            'prompt_version_consistency' => $promptConsistency,
            'polling' => [
                'note' => 'Frontend polling POST count is not persisted server-side. Estimate via manual UX review or future GET status endpoint.',
                'configured_interval_ms' => 2500,
                'configured_max_ms' => 60000,
            ],
            'frontend_telemetry' => [
                'available' => false,
                'note' => 'No privacy-safe AI explanation analytics pipeline installed. dataLayer exists for ecommerce only.',
            ],
        ];
    }

    /** @return Collection<int, LaboratoryResultAiExplanation> */
    private function explanationQuery(?CarbonInterface $since): Collection
    {
        $query = LaboratoryResultAiExplanation::query()->orderBy('id');

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->get();
    }

    /** @return Collection<int, AiExecution> */
    private function executionQuery(?CarbonInterface $since): Collection
    {
        $query = AiExecution::query()
            ->where('feature', LaboratoryResultAiExplanationContract::FEATURE);

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->orderBy('id')->get();
    }

    /** @return Collection<int, CustomerLaboratoryAiExplanationConsent> */
    private function consentQuery(?CarbonInterface $since): Collection
    {
        $query = CustomerLaboratoryAiExplanationConsent::query();

        if ($since) {
            $query->where('updated_at', '>=', $since);
        }

        return $query->get();
    }

    /** @param Collection<int, LaboratoryResultAiExplanation> $explanations @return array<string, int> */
    private function countByStatus(Collection $explanations): array
    {
        $counts = [];
        foreach (LaboratoryResultAiExplanationStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        foreach ($explanations as $explanation) {
            $counts[$explanation->status->value] = ($counts[$explanation->status->value] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param Collection<int, CustomerLaboratoryAiExplanationConsent> $consents @return array<string, mixed> */
    private function countConsents(Collection $consents): array
    {
        $accepted = $consents->where('status', CustomerLaboratoryAiExplanationConsentStatus::Accepted)->count();
        $declined = $consents->where('status', CustomerLaboratoryAiExplanationConsentStatus::Declined)->count();
        $decided = $accepted + $declined;

        return [
            'accepted' => $accepted,
            'declined' => $declined,
            'not_requested' => $consents->where('status', CustomerLaboratoryAiExplanationConsentStatus::NotRequested)->count(),
            'consent_rate' => $decided > 0 ? round($accepted / $decided, 4) : null,
        ];
    }

    /** @param Collection<int, AiExecution> $executions @return array<string, mixed> */
    private function summarizeExecutions(Collection $executions): array
    {
        $succeeded = $executions->where('status', AiExecution::STATUS_SUCCEEDED);
        $failed = $executions->where('status', AiExecution::STATUS_FAILED);

        $durations = $succeeded->pluck('duration_ms')->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v);
        $promptTokens = $succeeded->pluck('prompt_tokens')->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v);
        $completionTokens = $succeeded->pluck('completion_tokens')->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v);
        $totalTokens = $succeeded->pluck('total_tokens')->filter(fn ($v) => $v !== null)->map(fn ($v) => (int) $v);
        $costs = $succeeded->pluck('estimated_cost_usd')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);

        $errors429 = $failed->filter(fn (AiExecution $e) => $this->errorContains($e->error, ['429', 'rate limit']));
        $errorsTimeout = $failed->filter(fn (AiExecution $e) => $this->errorContains($e->error, ['timeout', 'timed out']));

        $avgCost = $costs->avg();
        $avgTokens = $totalTokens->avg();

        return [
            'total' => $executions->count(),
            'succeeded' => $succeeded->count(),
            'failed' => $failed->count(),
            'processing' => $executions->where('status', AiExecution::STATUS_PROCESSING)->count(),
            'models' => $executions->pluck('model')->filter()->unique()->values()->all(),
            'prompt_versions' => $executions->pluck('prompt_version')->filter()->unique()->values()->all(),
            'avg_duration_ms' => $durations->avg() !== null ? (int) round((float) $durations->avg()) : null,
            'avg_prompt_tokens' => $promptTokens->avg() !== null ? (int) round((float) $promptTokens->avg()) : null,
            'avg_completion_tokens' => $completionTokens->avg() !== null ? (int) round((float) $completionTokens->avg()) : null,
            'avg_total_tokens' => $avgTokens !== null ? (int) round((float) $avgTokens) : null,
            'avg_cost_usd' => $avgCost !== null ? round((float) $avgCost, 6) : null,
            'estimated_cost_per_100_usd' => $avgCost !== null && $avgTokens !== null
                ? round((float) $avgCost * 100, 4)
                : 'cost not available',
            'openai_errors_429' => $errors429->count(),
            'openai_errors_timeout' => $errorsTimeout->count(),
        ];
    }

    /** @param Collection<int, LaboratoryResultAiExplanation> $explanations @return array<string, int|null> */
    private function summarizeLatency(Collection $explanations): array
    {
        $readyRows = $explanations->filter(
            fn (LaboratoryResultAiExplanation $e) => $e->status === LaboratoryResultAiExplanationStatus::Ready
                && $e->generated_at !== null,
        );

        $requestToReady = $readyRows
            ->map(fn (LaboratoryResultAiExplanation $e) => $e->created_at?->diffInMilliseconds($e->generated_at))
            ->filter(fn ($ms) => $ms !== null);

        $failedRows = $explanations->where('status', LaboratoryResultAiExplanationStatus::Failed);

        return [
            'avg_request_to_ready_ms' => $requestToReady->avg() !== null
                ? (int) round((float) $requestToReady->avg())
                : null,
            'max_request_to_ready_ms' => $requestToReady->max() !== null ? (int) $requestToReady->max() : null,
            'ready_samples' => $requestToReady->count(),
            'failed_samples' => $failedRows->count(),
            'note_generating_timestamp' => 'No dedicated generating_at column; AiExecution.duration_ms used for OpenAI latency.',
        ];
    }

    /** @param Collection<int, AiExecution> $executions @return array<string, int> */
    private function summarizeSafetyFailures(Collection $executions): array
    {
        $failed = $executions->where('status', AiExecution::STATUS_FAILED);

        $schema = 0;
        $safety = 0;
        $factual = 0;
        $pii = 0;
        $other = 0;

        foreach ($failed as $execution) {
            $category = $this->classifyValidationError($execution->error);
            match ($category) {
                'schema' => $schema++,
                'safety' => $safety++,
                'factual' => $factual++,
                'pii' => $pii++,
                default => $other++,
            };
        }

        return [
            'schema_validation_failure' => $schema,
            'safety_validation_failure' => $safety,
            'factual_contradiction_failure' => $factual,
            'pii_output_failure' => $pii,
            'other_failure' => $other,
        ];
    }

    /** @param Collection<int, AiExecution> $executions @return array<string, mixed> */
    private function summarizePromptConsistency(Collection $executions): array
    {
        $expectedVersion = LaboratoryResultAiExplanationContract::PROMPT_VERSION;
        $expectedLabel = LaboratoryResultAiExplanationContract::PROMPT_VERSION_LABEL;

        $dbVersions = DB::table('ai_prompts')
            ->where('key', LaboratoryResultAiExplanationContract::PROMPT_KEY)
            ->where('status', 'active')
            ->pluck('version')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        $executionVersions = $executions->pluck('prompt_version')->filter()->unique()->values()->all();

        $consistent = $dbVersions === [$expectedVersion]
            && ($executionVersions === [] || $executionVersions === [$expectedVersion]);

        return [
            'expected_contract_version' => $expectedVersion,
            'expected_label' => $expectedLabel,
            'active_db_versions' => $dbVersions,
            'execution_prompt_versions' => $executionVersions,
            'consistent' => $consistent,
        ];
    }

    private function classifyValidationError(?string $error): string
    {
        $message = strtolower((string) $error);

        if ($message === '') {
            return 'other';
        }

        if (str_contains($message, 'prohibited pii')) {
            return 'pii';
        }

        if (str_contains($message, 'output contradicts')) {
            return 'factual';
        }

        if (str_contains($message, 'prohibited clinical language')) {
            return 'safety';
        }

        if (str_contains($message, 'unexpected fields')
            || str_contains($message, 'missing fields')
            || str_contains($message, 'must be a non-empty string')
            || str_contains($message, 'exceeds max length')) {
            return 'schema';
        }

        return 'other';
    }

    /** @param list<string> $needles */
    private function errorContains(?string $error, array $needles): bool
    {
        $haystack = strtolower((string) $error);

        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
