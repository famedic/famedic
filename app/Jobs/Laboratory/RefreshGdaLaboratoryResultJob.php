<?php

namespace App\Jobs\Laboratory;

use App\Actions\Laboratories\RefreshGdaLaboratoryResultAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshGdaLaboratoryResultJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(
        public int $resultStatusId,
    ) {
        $this->onQueue(config('services.gda.result_refresh.queue', 'default'));
        $this->uniqueFor = (int) config('services.gda.result_refresh.lock_seconds', 600);
    }

    public function uniqueId(): string
    {
        return 'laboratory-result-refresh:'.$this->resultStatusId;
    }

    public function handle(RefreshGdaLaboratoryResultAction $refreshGdaLaboratoryResultAction): void
    {
        Log::info('laboratory_result_refresh_job_started', [
            'result_status_id' => $this->resultStatusId,
            'attempt' => $this->attempts(),
        ]);

        $refreshGdaLaboratoryResultAction->execute($this->resultStatusId);
    }
}
