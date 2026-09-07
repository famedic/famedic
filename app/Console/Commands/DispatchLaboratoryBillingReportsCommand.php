<?php

namespace App\Console\Commands;

use App\Jobs\LaboratoryBilling\GenerateLaboratoryBillingReportJob;
use App\Models\LaboratoryBillingReportRun;
use App\Models\LaboratoryBillingReportSchedule;
use App\Services\LaboratoryBilling\Reports\LaboratoryBillingReportScheduleCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DispatchLaboratoryBillingReportsCommand extends Command
{
    protected $signature = 'laboratory-billing:dispatch-reports';

    protected $description = 'Dispatch due laboratory billing automatic reports.';

    public function handle(LaboratoryBillingReportScheduleCalculator $calculator): int
    {
        $dueScheduleIds = LaboratoryBillingReportSchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->pluck('id');

        foreach ($dueScheduleIds as $scheduleId) {
            Cache::lock("laboratory-billing-report-schedule:{$scheduleId}", 120)->get(function () use ($scheduleId, $calculator) {
                $run = DB::transaction(function () use ($scheduleId, $calculator) {
                    $schedule = LaboratoryBillingReportSchedule::query()
                        ->whereKey($scheduleId)
                        ->lockForUpdate()
                        ->first();

                    if (! $schedule || ! $schedule->is_active || ! $schedule->next_run_at || $schedule->next_run_at->gt(now())) {
                        return null;
                    }

                    $idempotencyKey = $calculator->idempotencyKey($schedule, $schedule->next_run_at);
                    $run = LaboratoryBillingReportRun::query()->firstOrCreate(
                        ['idempotency_key' => $idempotencyKey],
                        [
                            'schedule_id' => $schedule->id,
                            'run_type' => LaboratoryBillingReportRun::TYPE_SCHEDULED,
                            'status' => LaboratoryBillingReportRun::STATUS_PENDING,
                            'intended_for_at' => $schedule->next_run_at,
                            'recipients' => $schedule->recipients,
                            'filters' => $schedule->filters ?? [],
                        ]
                    );

                    $schedule->update([
                        'next_run_at' => $calculator->nextRunAt($schedule, now()->addMinute()),
                    ]);

                    return $run;
                });

                if ($run && $run->wasRecentlyCreated) {
                    GenerateLaboratoryBillingReportJob::dispatch($run->id);
                }
            });
        }

        $this->info('Laboratory billing report dispatch finished.');

        return self::SUCCESS;
    }
}
