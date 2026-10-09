<?php

namespace App\Jobs;

use App\Models\AiExecution;
use App\Models\LaboratoryPurchase;
use App\Services\LaboratoryPreparation\LaboratoryPreparationFidelityValidationException;
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

    public function __construct(
        public int $laboratoryPurchaseId,
        public ?int $aiExecutionId = null,
        public ?string $sourceHash = null,
    ) {
        $this->afterCommit();
    }

    public function handle(
        LaboratoryPreparationSummaryService $service,
        LaboratoryPreparationSummaryNotificationService $notificationService,
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

        if ($this->sourceHash && ! $service->deterministicV3Enabled()) {
            Log::info('laboratory_preparation_summary_job_skipped_v3_disabled', [
                'purchase_id' => $purchase->id,
                'source_hash' => $this->sourceHash,
            ]);

            return;
        }

        $execution = $this->aiExecutionId
            ? AiExecution::query()->find($this->aiExecutionId)
            : $service->queueExecution($purchase);

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
}
