<?php

namespace App\Services\LaboratoryBilling\Reports;

use App\Models\LaboratoryBillingReportSchedule;
use Illuminate\Support\Carbon;

class LaboratoryBillingReportScheduleCalculator
{
    public function nextRunAt(LaboratoryBillingReportSchedule $schedule, ?Carbon $after = null): ?Carbon
    {
        $weekdays = collect($schedule->weekdays ?? [])
            ->map(fn ($day) => (int) $day)
            ->filter(fn ($day) => $day >= 1 && $day <= 7)
            ->unique()
            ->values();

        if ($weekdays->isEmpty() || blank($schedule->send_time) || ! $schedule->is_active) {
            return null;
        }

        $timezone = $schedule->timezone ?: LaboratoryBillingReportPeriodResolver::TIMEZONE;
        $cursor = Carbon::parse($after ?? now())->timezone($timezone);

        for ($offset = 0; $offset <= 14; $offset++) {
            $candidate = $cursor->copy()->addDays($offset)
                ->setTimeFromTimeString((string) $schedule->send_time);

            if (! $weekdays->contains((int) $candidate->isoWeekday())) {
                continue;
            }

            if ($candidate->gt($cursor)) {
                return $candidate;
            }
        }

        return null;
    }

    public function idempotencyKey(LaboratoryBillingReportSchedule $schedule, Carbon $intendedFor): string
    {
        $local = $intendedFor->copy()->timezone($schedule->timezone ?: LaboratoryBillingReportPeriodResolver::TIMEZONE);

        return implode(':', [
            'laboratory-billing-report',
            $schedule->id,
            $local->toDateString(),
            $local->format('H:i'),
        ]);
    }
}
