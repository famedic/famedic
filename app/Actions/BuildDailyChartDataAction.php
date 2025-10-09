<?php

namespace App\Actions;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class BuildDailyChartDataAction
{
    public function __invoke(
        Collection $purchases,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): array {
        if (!$startDate) {
            $minCreatedAt = $purchases->min('created_at');
            $startDate = $minCreatedAt
                ? localizedDate($minCreatedAt)->setTimezone('America/Monterrey')->startOfDay()
                : Carbon::now('America/Monterrey')->startOfDay();
        } else {
            $startDate = $startDate->setTimezone('America/Monterrey')->startOfDay();
        }

        if (!$endDate) {
            $maxCreatedAt = $purchases->max('created_at');
            $endDate = $maxCreatedAt
                ? localizedDate($maxCreatedAt)->setTimezone('America/Monterrey')->endOfDay()
                : Carbon::now('America/Monterrey')->endOfDay();
        } else {
            $endDate = $endDate->setTimezone('America/Monterrey')->endOfDay();
        }

        if (!$this->isValidDateRange($startDate, $endDate)) {
            return [];
        }

        $hasADateFromPreviousYears = $startDate->year !== $endDate->year;

        $dataPoints = collect($this->generateDateRange($startDate, $endDate))->map(
            function (Carbon $localDate) use ($purchases, $hasADateFromPreviousYears) {
                $startUtc = $localDate->copy()->startOfDay()->setTimezone('UTC');
                $endUtc   = $localDate->copy()->endOfDay()->setTimezone('UTC');

                $dailyTotal = $purchases
                    ->whereBetween('created_at', [$startUtc, $endUtc])
                    ->sum('total_cents');

                return [
                    'date'           => $hasADateFromPreviousYears
                        ? $localDate->isoFormat('MMM D, Y')
                        : $localDate->isoFormat('MMM D'),
                    'value'          => $dailyTotal,
                    'formattedValue' => formattedCentsPrice($dailyTotal),
                ];
            }
        );

        $averageValue = $dataPoints->avg('value');
        $totalValue   = $dataPoints->sum('value');

        return [
            'dataPoints'    => $dataPoints->all(),
            'averagePerDay' => formattedCentsPrice($averageValue),
            'total'         => formattedCentsPrice($totalValue),
        ];
    }

    protected function isValidDateRange(Carbon $startDate, Carbon $endDate): bool
    {
        return $startDate->lte($endDate);
    }

    protected function generateDateRange(Carbon $startDate, Carbon $endDate): Collection
    {
        return collect($startDate->toPeriod($endDate, '1 day'));
    }
}
