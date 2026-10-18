<?php

namespace App\Jobs;

use App\Services\LaboratoryConfirmation\LaboratoryConfirmationCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CoordinateLaboratoryPurchaseConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Recovery is owned by the durable ledger, not blind queue retries.

    public int $timeout = 60;

    public function __construct(public int $confirmationId)
    {
        $this->onConnection(config('laboratory-confirmation.connection', 'database'));
        $this->onQueue(config('laboratory-confirmation.queue', 'laboratory-confirmations'));
        $this->afterCommit();
    }

    public function handle(LaboratoryConfirmationCoordinator $coordinator): void
    {
        try {
            $coordinator->coordinate($this->confirmationId);
        } catch (\Throwable) {
            // Never serialize render/provider exception text into worker logs or failed_jobs.
            throw new \App\Services\LaboratoryPreparation\LaboratoryPreparationOperationalException('job');
        }
    }
}
