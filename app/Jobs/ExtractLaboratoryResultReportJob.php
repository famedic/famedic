<?php

namespace App\Jobs;

use App\Services\LaboratoryResults\Extraction\LaboratoryResultExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExtractLaboratoryResultReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $laboratoryResultVersionId,
    ) {
        $this->afterCommit();
    }

    public function handle(LaboratoryResultExtractionService $service): void
    {
        if (! config('laboratory-results.structured_extraction.enabled', false)) {
            return;
        }

        $service->extractForVersion($this->laboratoryResultVersionId);
    }
}
