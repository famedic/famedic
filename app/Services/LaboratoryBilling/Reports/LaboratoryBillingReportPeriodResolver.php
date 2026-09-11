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
            // Rolling day windows are inclusive and include the current local day through 23:59:59.
            LaboratoryBillingReportSchedule::PERIOD_LAST_15_DAYS => $this->rollingDays($now, 15),
            LaboratoryBillingReportSchedule::PERIOD_LAST_30_DAYS => $this->rollingDays($now, 30),
            LaboratoryBillingReportSchedule::PERIOD_LAST_60_DAYS => $this->rollingDays($now, 60),
            LaboratoryBillingReportSchedule::PERIOD_LAST_90_DAYS => $this->rollingDays($now, 90),
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
            LaboratoryBillingReportSchedule::PERIOD_LAST_2_COMPLETE_MONTHS => $this->completeMonthsBeforeCurrent($now, 2),
            LaboratoryBillingReportSchedule::PERIOD_LAST_3_COMPLETE_MONTHS => $this->completeMonthsBeforeCurrent($now, 3),
            LaboratoryBillingReportSchedule::PERIOD_CUSTOM_RANGE => [
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

    public function labelFor(string $periodType): string
    {
        return collect(LaboratoryBillingReportSchedule::manualPeriodOptions())
            ->firstWhere('value', $periodType)['label'] ?? 'Día anterior';
    }

    public function previewOptions(?Carbon $now = null): array
    {
        $now = Carbon::parse($now ?? now())->timezone(self::TIMEZONE);

        return collect(LaboratoryBillingReportSchedule::periodOptions())
            ->map(function (array $option) use ($now) {
                $period = $this->resolve($option['value'], $now);

                return [
                    ...$option,
                    'example' => $this->dateOnlyLabel($period['start'], $period['end']),
                    'full_label' => $period['label'],
                ];
            })
            ->all();
    }

    public function dateOnlyLabel(Carbon $start, Carbon $end): string
    {
        return 'Del '.$this->spanishDate($start->copy()->timezone(self::TIMEZONE))
            .' al '.$this->spanishDate($end->copy()->timezone(self::TIMEZONE));
    }

    public function customDayCount(string $customFrom, string $customTo): int
    {
        $start = Carbon::parse($customFrom, self::TIMEZONE)->startOfDay();
        $end = Carbon::parse($customTo, self::TIMEZONE)->startOfDay();

        return $start->diffInDays($end) + 1;
    }

    private function rollingDays(Carbon $now, int $days): array
    {
        return [
            $now->copy()->subDays($days - 1)->startOfDay(),
            $now->copy()->endOfDay(),
        ];
    }

    private function completeMonthsBeforeCurrent(Carbon $now, int $months): array
    {
        $end = $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay();

        return [
            $end->copy()->subMonthsNoOverflow($months - 1)->startOfMonth()->startOfDay(),
            $end,
        ];
    }

    private function spanishDate(Carbon $date): string
    {
        $months = [
            1 => 'enero',
            2 => 'febrero',
            3 => 'marzo',
            4 => 'abril',
            5 => 'mayo',
            6 => 'junio',
            7 => 'julio',
            8 => 'agosto',
            9 => 'septiembre',
            10 => 'octubre',
            11 => 'noviembre',
            12 => 'diciembre',
        ];

        return $date->day.' de '.$months[$date->month].' de '.$date->year;
    }
}
