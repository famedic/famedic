<?php

namespace App\Actions;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class BuildDailyCountChartDataAction
{
    public function __invoke(
        Collection $items,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): array {
        if (!$startDate) {
            $minCreatedAt = $items->min('created_at');
            $startDate = $minCreatedAt
                ? localizedDate($minCreatedAt)->setTimezone('America/Monterrey')->startOfDay()
                : Carbon::now('America/Monterrey')->startOfDay();
        } else {
            $startDate = $startDate->setTimezone('America/Monterrey')->startOfDay();
        }

        if (!$endDate) {
            $maxCreatedAt = $items->max('created_at');
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

        // Pre-aggregate counts per local day to avoid scanning for each day
        $countsByDate = $items
            ->filter(function ($item) {
                return ! empty($item->created_at);
            })
            ->reduce(function (array $carry, $item) {
                $createdAt = $item->created_at instanceof Carbon
                    ? $item->created_at->copy()
                    : Carbon::parse($item->created_at);
                $createdAt = $createdAt->setTimezone('America/Monterrey');
                $key = $createdAt->toDateString(); // YYYY-MM-DD (local day)
                $carry[$key] = ($carry[$key] ?? 0) + 1;
                return $carry;
            }, []);

        $dataPoints = collect($this->generateDateRange($startDate, $endDate))->map(function (Carbon $localDate) use ($countsByDate, $hasADateFromPreviousYears) {
            $key = $localDate->toDateString();
            $dailyCount = $countsByDate[$key] ?? 0;

            return [
                'date'           => $hasADateFromPreviousYears
                    ? $localDate->isoFormat('MMM D, Y')
                    : $localDate->isoFormat('MMM D'),
                'value'          => $dailyCount,
                'formattedValue' => number_format($dailyCount) . ' registros',
            ];
        });

        $averageValue = $dataPoints->avg('value');
        $totalValue   = $dataPoints->sum('value');

        return [
            'dataPoints'    => $dataPoints->all(),
            'averagePerDay' => number_format(round($averageValue, 2)),
            'total'         => number_format($totalValue),
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
