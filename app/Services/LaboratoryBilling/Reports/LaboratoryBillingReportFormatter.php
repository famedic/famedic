<?php

namespace App\Services\LaboratoryBilling\Reports;

use App\Models\LaboratoryBillingReportSchedule;
use Illuminate\Support\Carbon;

class LaboratoryBillingReportFormatter
{
    public function averageResponseDuration(?float $hours): array
    {
        if ($hours === null) {
            return [
                'value' => 'Sin datos',
                'detail' => null,
            ];
        }

        $totalMinutes = max(0, (int) floor($hours * 60));
        $days = intdiv($totalMinutes, 1440);
        $remainingMinutes = $totalMinutes % 1440;
        $hoursPart = intdiv($remainingMinutes, 60);
        $minutes = $remainingMinutes % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $this->unit($days, 'día', 'días');
        }
        if ($hoursPart > 0) {
            $parts[] = $this->unit($hoursPart, 'hora', 'horas');
        }
        if ($parts === [] && $minutes > 0) {
            $parts[] = $this->unit($minutes, 'minuto', 'minutos');
        }
        if ($parts === []) {
            $parts[] = '0 minutos';
        }

        return [
            'value' => implode(' ', $parts),
            'detail' => $minutes > 0 && ($days > 0 || $hoursPart > 0)
                ? $this->unit($minutes, 'minuto adicional', 'minutos adicionales')
                : null,
        ];
    }

    public function periodName(?string $periodType): string
    {
        if ($periodType === LaboratoryBillingReportSchedule::PERIOD_CUSTOM_RANGE) {
            return 'Rango personalizado';
        }

        return collect(LaboratoryBillingReportSchedule::manualPeriodOptions())
            ->firstWhere('value', $periodType)['label'] ?? 'Periodo seleccionado';
    }

    public function periodDateLabel(Carbon $start, Carbon $end): string
    {
        return 'Del '.$this->spanishDate($start->copy()->timezone(LaboratoryBillingReportPeriodResolver::TIMEZONE))
            .' al '.$this->spanishDate($end->copy()->timezone(LaboratoryBillingReportPeriodResolver::TIMEZONE));
    }

    private function unit(int $value, string $singular, string $plural): string
    {
        return $value.' '.($value === 1 ? $singular : $plural);
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
