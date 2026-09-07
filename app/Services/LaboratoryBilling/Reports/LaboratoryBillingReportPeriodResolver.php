<?php

namespace App\Services\LaboratoryBilling\Reports;

use App\Models\LaboratoryBillingReportSchedule;
use Illuminate\Support\Carbon;

class LaboratoryBillingReportPeriodResolver
{
    public const TIMEZONE = 'America/Monterrey';

    public function resolve(string $periodType, ?Carbon $now = null, ?string $customFrom = null, ?string $customTo = null): array
    {
        $now = Carbon::parse($now ?? now())->timezone(self::TIMEZONE);

        [$start, $end] = match ($periodType) {
            LaboratoryBillingReportSchedule::PERIOD_PREVIOUS_DAY => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],
            LaboratoryBillingReportSchedule::PERIOD_LAST_7_DAYS => [
                $now->copy()->subDays(6)->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            LaboratoryBillingReportSchedule::PERIOD_CURRENT_WEEK => [
                $now->copy()->startOfWeek()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            LaboratoryBillingReportSchedule::PERIOD_PREVIOUS_WEEK => [
                $now->copy()->subWeek()->startOfWeek()->startOfDay(),
                $now->copy()->subWeek()->endOfWeek()->endOfDay(),
            ],
            LaboratoryBillingReportSchedule::PERIOD_CURRENT_MONTH => [
                $now->copy()->startOfMonth()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            LaboratoryBillingReportSchedule::PERIOD_PREVIOUS_MONTH => [
                $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay(),
            ],
            'custom_range' => [
                Carbon::parse($customFrom, self::TIMEZONE)->startOfDay(),
                Carbon::parse($customTo, self::TIMEZONE)->endOfDay(),
            ],
            default => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],
        };

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [
            'start' => $start,
            'end' => $end,
            'start_utc' => $start->copy()->utc(),
            'end_utc' => $end->copy()->utc(),
            'label' => $start->isoFormat('D MMM Y h:mm a').' - '.$end->isoFormat('D MMM Y h:mm a'),
            'timezone' => self::TIMEZONE,
        ];
    }
}
