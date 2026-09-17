<?php

namespace App\Console\Commands;

use App\Enums\LaboratoryResultStatus as LaboratoryResultStatusEnum;
use App\Jobs\Laboratory\RefreshGdaLaboratoryResultJob;
use App\Models\LaboratoryResultStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchDueLaboratoryResultRefreshes extends Command
{
    protected $signature = 'laboratory-results:dispatch-due-refreshes';

    protected $description = 'Dispatch GDA refresh jobs for pending laboratory result statuses that are due.';

    public function handle(): int
    {
        if (! config('services.gda.result_refresh.enabled', false)) {
            $this->info('Laboratory result refresh is disabled.');

            return self::SUCCESS;
        }

        $chunkSize = max(1, (int) config('services.gda.result_refresh.chunk_size', 100));
        $dispatched = 0;

        LaboratoryResultStatus::query()
            ->where('status', LaboratoryResultStatusEnum::PendingInterpretation->value)
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', now())
            ->orderBy('id')
            ->chunkById($chunkSize, function ($statuses) use (&$dispatched): void {
                foreach ($statuses as $status) {
                    RefreshGdaLaboratoryResultJob::dispatch($status->id);
                    $dispatched++;
                }
            });

        Log::info('laboratory_result_refresh_dispatch_completed', [
            'dispatched' => $dispatched,
        ]);

        $this->info("Dispatched {$dispatched} laboratory result refresh job(s).");

        return self::SUCCESS;
    }
}
