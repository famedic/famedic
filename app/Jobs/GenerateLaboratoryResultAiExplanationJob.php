<?php

namespace App\Jobs;

use App\Models\LaboratoryResultAiExplanation;
use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationFeatureGuard;
use App\Services\LaboratoryResults\AiExplanation\LaboratoryResultAiExplanationGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateLaboratoryResultAiExplanationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $laboratoryResultAiExplanationId)
    {
        $this->afterCommit();
    }

    public function handle(
        LaboratoryResultAiExplanationGenerator $generator,
        LaboratoryResultAiExplanationFeatureGuard $featureGuard,
    ): void {
        if (! $featureGuard->isEffectiveEnabled()) {
            return;
        }

        $explanation = LaboratoryResultAiExplanation::query()
            ->with('observation.report')
            ->find($this->laboratoryResultAiExplanationId);

        if (! $explanation) {
            return;
        }

        try {
            $generator->generate($explanation);
        } catch (\Throwable $exception) {
            if ($this->attempts() >= $this->tries) {
                report($exception);

                return;
            }

            throw $exception;
        }
    }
}
