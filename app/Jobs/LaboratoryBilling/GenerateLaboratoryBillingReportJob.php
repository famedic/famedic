<?php

namespace App\Jobs\LaboratoryBilling;

use App\Models\LaboratoryBillingReportRun;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportDataService;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportDeliveryService;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportPeriodResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateLaboratoryBillingReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $runId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        LaboratoryBillingReportPeriodResolver $periods,
        LaboratoryBillingReportDataService $data,
        LaboratoryBillingReportDeliveryService $delivery,
    ): void {
        $run = LaboratoryBillingReportRun::query()->with('schedule')->find($this->runId);

        if (! $run || ! $run->schedule) {
            return;
        }

        if ($run->alreadySent()) {
            Log::info('[Laboratory Billing Report] skipped already sent run', ['run_id' => $this->runId]);

            return;
        }

        $run->update([
            'status' => LaboratoryBillingReportRun::STATUS_PROCESSING,
            'started_at' => $run->started_at ?? now(),
        ]);

        try {
            $periodType = (string) data_get($run->filters, '_period_type', $run->schedule->period_type);
            $period = $periods->resolve(
                $periodType,
                now(),
                data_get($run->filters, '_custom_from'),
                data_get($run->filters, '_custom_to'),
            );

            $reportData = $data->build($period, $run->filters ?? [], now(LaboratoryBillingReportPeriodResolver::TIMEZONE));

            $run->update([
                'period_start' => $period['start_utc'],
                'period_end' => $period['end_utc'],
                'backlog_as_of' => now(),
                'metrics' => $reportData['metrics'],
            ]);

            $delivery->deliver($run->schedule, $run->fresh(), $reportData);

            $run->update([
                'status' => LaboratoryBillingReportRun::STATUS_SENT,
                'sent_at' => now(),
                'finished_at' => now(),
                'error_message' => null,
            ]);

            $run->schedule->update([
                'last_run_at' => now(),
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => LaboratoryBillingReportRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $this->sanitizeError($e),
            ]);

            throw $e;
        }
    }

    private function sanitizeError(Throwable $e): string
    {
        return mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000);
    }
}
