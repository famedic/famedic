<?php

namespace App\Jobs;

use App\Models\AiExecution;
use App\Models\LaboratoryPurchase;
use App\Services\LaboratoryPreparation\LaboratoryPreparationFidelityValidationException;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
    ) {
        $this->afterCommit();
    }

    public function handle(LaboratoryPreparationSummaryService $service): void
    {
        $purchase = LaboratoryPurchase::query()
            ->with('laboratoryPurchaseItems')
            ->find($this->laboratoryPurchaseId);

        if (! $purchase) {
            return;
        }

        $execution = $this->aiExecutionId
            ? AiExecution::query()->find($this->aiExecutionId)
            : $service->queueExecution($purchase);

        try {
            $service->generate($purchase, $execution, throwOnFailure: true);
        } catch (LaboratoryPreparationFidelityValidationException $exception) {
            $this->fail($exception);
        }
    }
}
