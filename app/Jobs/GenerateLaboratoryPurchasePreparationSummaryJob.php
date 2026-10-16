<?php

namespace App\Jobs;

use App\Models\AiExecution;
use App\Models\LaboratoryPurchase;
use App\Services\LaboratoryPreparation\LaboratoryPreparationFidelityValidationException;
use App\Services\LaboratoryPreparation\LaboratoryPreparationEligibility;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryNotificationService;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateLaboratoryPurchasePreparationSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public const MODE_GENERATE = 'generate';

    public const MODE_SHADOW_ONLY = 'shadow_only';

    public function __construct(
        public int $laboratoryPurchaseId,
        public ?int $aiExecutionId = null,
        public ?string $sourceHash = null,
        public string $mode = self::MODE_GENERATE,
    ) {
        $this->afterCommit();
    }

    public function handle(
        LaboratoryPreparationSummaryService $service,
        LaboratoryPreparationSummaryNotificationService $notificationService,
        LaboratoryPreparationEligibility $eligibilityService,
    ): void {
        Log::info('laboratory_preparation_summary_job_started', [
            'purchase_id' => $this->laboratoryPurchaseId,
            'ai_execution_id' => $this->aiExecutionId,
            'source_hash' => $this->sourceHash,
        ]);

        $purchase = LaboratoryPurchase::query()
            ->with('laboratoryPurchaseItems')
            ->find($this->laboratoryPurchaseId);

        if (! $purchase) {
            Log::warning('laboratory_preparation_summary_job_purchase_missing', [
                'purchase_id' => $this->laboratoryPurchaseId,
                'ai_execution_id' => $this->aiExecutionId,
            ]);

            return;
        }

        $v3Enabled = $service->deterministicV3Enabled();
        $shadowEnabled = $service->deterministicV3ShadowEnabled();

        if ($this->mode === self::MODE_SHADOW_ONLY) {
            if ($v3Enabled || ! $shadowEnabled) {
                Log::info('laboratory_preparation_shadow_job_skipped_flags_changed', [
                    'purchase_id' => $purchase->id,
                    'v3_enabled' => $v3Enabled,
                    'shadow_enabled' => $shadowEnabled,
                ]);

                return;
            }

            $eligibility = $eligibilityService->evaluate($purchase);
            if (! $eligibility->eligible) {
                $this->logIneligiblePurchase($purchase, $eligibility->reason, $eligibility->paymentMethod);

                return;
            }

            $expectedHash = $this->sourceHash ?? $service->deterministicV3SourceHash($purchase);
            $service->runDeterministicV3Shadow($purchase, $expectedHash);

            return;
        }

        if (! $v3Enabled) {
            Log::info('laboratory_preparation_job_skipped_v3_disabled', [
                'purchase_id' => $purchase->id,
                'shadow_enabled' => $shadowEnabled,
            ]);

            return;
        }

        $eligibility = $eligibilityService->evaluate($purchase);
        if (! $eligibility->eligible) {
            $this->logIneligiblePurchase($purchase, $eligibility->reason, $eligibility->paymentMethod);

            return;
        }

        $execution = $this->aiExecutionId
            ? AiExecution::query()->find($this->aiExecutionId)
            : null;

        $expectedSourceHash = $execution?->input_hash ?? $this->sourceHash;
        if ($expectedSourceHash && $service->expectedSourceHash($purchase) !== $expectedSourceHash) {
            Log::info('laboratory_preparation_summary_job_skipped_stale_source', [
                'purchase_id' => $purchase->id,
                'ai_execution_id' => $execution?->id,
                'expected_source_hash' => $expectedSourceHash,
            ]);

            return;
        }

        $hadCurrentSummary = $service->hasCurrentGeneratedSummary($purchase);
        $shadowSourceHash = $service->deterministicV3ShadowEffectiveEnabled()
            ? $service->deterministicV3SourceHash($purchase)
            : null;

        try {
            $summary = $service->generate($purchase, $execution, throwOnFailure: true);

            Log::info('laboratory_preparation_summary_job_completed', [
                'purchase_id' => $purchase->id,
                'ai_execution_id' => $execution?->id,
                'summary_id' => $summary?->id,
                'summary_status' => $summary?->status,
                'generated_at' => $summary?->generated_at?->toIso8601String(),
            ]);

            if ($summary && ! $hadCurrentSummary && $shadowSourceHash !== null) {
                $service->runDeterministicV3Shadow(
                    $purchase->fresh('laboratoryPurchaseItems') ?? $purchase,
                    $shadowSourceHash,
                );
            }

            if ($summary) {
                $notificationService->notifyIfNeeded($summary);
            }
        } catch (LaboratoryPreparationFidelityValidationException $exception) {
            Log::warning('laboratory_preparation_summary_job_fidelity_failed', [
                'purchase_id' => $purchase->id,
                'ai_execution_id' => $execution?->id,
                'message' => $exception->getMessage(),
            ]);

            $summary = $service->generateFallbackFromSource($purchase, $execution);

            Log::info('laboratory_preparation_summary_job_fallback_completed', [
                'purchase_id' => $purchase->id,
                'ai_execution_id' => $execution?->id,
                'summary_id' => $summary?->id,
                'summary_status' => $summary?->status,
                'generated_at' => $summary?->generated_at?->toIso8601String(),
            ]);

            if ($summary && ! $hadCurrentSummary && $shadowSourceHash !== null) {
                $service->runDeterministicV3Shadow(
                    $purchase->fresh('laboratoryPurchaseItems') ?? $purchase,
                    $shadowSourceHash,
                );
            }

            if ($summary) {
                $notificationService->notifyIfNeeded($summary);
            }
        } catch (\Throwable $exception) {
            Log::error('laboratory_preparation_summary_job_failed', [
                'purchase_id' => $purchase->id,
                'ai_execution_id' => $execution?->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function logIneligiblePurchase(LaboratoryPurchase $purchase, string $reason, ?string $paymentMethod): void
    {
        Log::info('laboratory_preparation_job_skipped_ineligible_purchase', [
            'purchase_id' => $purchase->id,
            'reason' => $reason,
            'payment_method' => $paymentMethod,
        ]);
    }
}
