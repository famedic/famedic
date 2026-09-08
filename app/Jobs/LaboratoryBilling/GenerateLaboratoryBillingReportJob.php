<?php

namespace App\Jobs\LaboratoryBilling;

use App\Models\LaboratoryBillingReportRun;
use App\Models\LaboratoryBillingReportSchedule;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportDataService;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportDeliveryService;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportPeriodResolver;
use InvalidArgumentException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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
            Log::warning('[Laboratory Billing Report] job skipped missing run or schedule', [
                'run_id' => $this->runId,
                'run_found' => (bool) $run,
            ]);

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

            Log::info('[Laboratory Billing Report] job started', [
                'run_id' => $run->id,
                'schedule_id' => $run->schedule_id,
                'run_type' => $run->run_type,
                'period_type' => $periodType,
                'recipients_count' => count($run->recipients ?? []),
            ]);

            if ($run->run_type === LaboratoryBillingReportRun::TYPE_SCHEDULED && $periodType === LaboratoryBillingReportSchedule::PERIOD_CUSTOM_RANGE) {
                throw new InvalidArgumentException('Scheduled reports cannot use a custom date range.');
            }

            $storedStart = $run->getRawOriginal('period_start');
            $storedEnd = $run->getRawOriginal('period_end');
            $period = $storedStart && $storedEnd
                ? [
                    'start' => Carbon::parse($storedStart, 'UTC')->timezone(LaboratoryBillingReportPeriodResolver::TIMEZONE),
                    'end' => Carbon::parse($storedEnd, 'UTC')->timezone(LaboratoryBillingReportPeriodResolver::TIMEZONE),
                    'start_utc' => Carbon::parse($storedStart, 'UTC'),
                    'end_utc' => Carbon::parse($storedEnd, 'UTC'),
                    'label' => Carbon::parse($storedStart, 'UTC')->timezone(LaboratoryBillingReportPeriodResolver::TIMEZONE)->isoFormat('D MMM Y h:mm a').' - '.Carbon::parse($storedEnd, 'UTC')->timezone(LaboratoryBillingReportPeriodResolver::TIMEZONE)->isoFormat('D MMM Y h:mm a'),
                    'timezone' => LaboratoryBillingReportPeriodResolver::TIMEZONE,
                ]
                : $periods->resolve(
                    $periodType,
                    now(),
                    data_get($run->filters, '_custom_from'),
                    data_get($run->filters, '_custom_to'),
                );

            $reportData = $data->build($period, $run->filters ?? [], now(LaboratoryBillingReportPeriodResolver::TIMEZONE));

            Log::info('[Laboratory Billing Report] report data built', [
                'run_id' => $run->id,
                'schedule_id' => $run->schedule_id,
                'period_start' => $period['start_utc']->toIso8601String(),
                'period_end' => $period['end_utc']->toIso8601String(),
                'received' => data_get($reportData, 'metrics.received'),
                'completed' => data_get($reportData, 'metrics.completed'),
                'pending_backlog' => data_get($reportData, 'metrics.pending_backlog'),
                'overdue' => data_get($reportData, 'metrics.overdue'),
            ]);

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

            Log::info('[Laboratory Billing Report] job sent', [
                'run_id' => $run->id,
                'schedule_id' => $run->schedule_id,
                'delivery_method' => $run->fresh()->delivery_method,
                'sent_at' => optional($run->fresh()->sent_at)->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => LaboratoryBillingReportRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $this->sanitizeError($e),
            ]);

            Log::error('[Laboratory Billing Report] job failed', [
                'run_id' => $run->id,
                'schedule_id' => $run->schedule_id,
                'error' => $this->sanitizeError($e),
            ]);

            throw $e;
        }
    }

    private function sanitizeError(Throwable $e): string
    {
        return mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000);
    }
}
