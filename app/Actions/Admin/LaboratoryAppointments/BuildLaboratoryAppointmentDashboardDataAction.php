<?php

namespace App\Actions\Admin\LaboratoryAppointments;

use App\Models\LaboratoryAppointment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BuildLaboratoryAppointmentDashboardDataAction
{
    public function __invoke(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $tz = 'America/Monterrey';

        if (! $startDate) {
            $startDate = Carbon::now($tz)->subDays(29)->startOfDay();
        } else {
            $startDate = $startDate->copy()->setTimezone($tz)->startOfDay();
        }

        if (! $endDate) {
            $endDate = Carbon::now($tz)->endOfDay();
        } else {
            $endDate = $endDate->copy()->setTimezone($tz)->endOfDay();
        }

        if ($startDate->gt($endDate)) {
            $tmp = $startDate->copy();
            $startDate = $endDate->copy()->startOfDay();
            $endDate = $tmp->copy()->endOfDay();
        }

        $startUtc = $startDate->copy()->startOfDay()->utc();
        $endUtc = $endDate->copy()->endOfDay()->utc();

        $createdInRange = LaboratoryAppointment::query()
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->get(['created_at', 'updated_at', 'confirmed_at']);

        $confirmedInRange = LaboratoryAppointment::query()
            ->whereNotNull('confirmed_at')
            ->whereBetween('confirmed_at', [$startUtc, $endUtc])
            ->get(['created_at', 'updated_at', 'confirmed_at']);

        $days = collect($startDate->toPeriod($endDate, '1 day'));
        $dayKeys = $days->map(fn (Carbon $d) => $d->format('Y-m-d'))->all();

        $solicitudesPorDia = array_fill_keys($dayKeys, 0);
        $confirmacionesPorDia = array_fill_keys($dayKeys, 0);

        foreach ($createdInRange as $row) {
            $key = Carbon::parse($row->created_at)->timezone($tz)->format('Y-m-d');
            if (array_key_exists($key, $solicitudesPorDia)) {
                $solicitudesPorDia[$key]++;
            }
        }

        foreach ($confirmedInRange as $row) {
            $key = Carbon::parse($row->confirmed_at)->timezone($tz)->format('Y-m-d');
            if (array_key_exists($key, $confirmacionesPorDia)) {
                $confirmacionesPorDia[$key]++;
            }
        }

        $hasADateFromPreviousYears = $startDate->year !== $endDate->year;

        $dataPoints = $days->map(function (Carbon $localDate) use (
            $solicitudesPorDia,
            $confirmacionesPorDia,
            $hasADateFromPreviousYears,
            $tz
        ) {
            $key = $localDate->format('Y-m-d');
            $localDate = $localDate->copy()->setTimezone($tz);

            return [
                'date' => $hasADateFromPreviousYears
                    ? $localDate->isoFormat('MMM D, Y')
                    : $localDate->isoFormat('MMM D'),
                'solicitudes' => $solicitudesPorDia[$key] ?? 0,
                'confirmaciones' => $confirmacionesPorDia[$key] ?? 0,
            ];
        });

        $solicitudesSinConfirmar = $createdInRange->whereNull('confirmed_at')->count();

        $horasHastaConfirmacion = $confirmedInRange->map(function ($row) {
            $c = Carbon::parse($row->confirmed_at);
            $created = Carbon::parse($row->created_at);

            return max(0, $c->diffInSeconds($created, true) / 3600);
        })->filter()->values();

        $horasSolicitudAActualizacion = $createdInRange->map(function ($row) {
            $u = Carbon::parse($row->updated_at);
            $created = Carbon::parse($row->created_at);

            return max(0, $u->diffInSeconds($created, true) / 3600);
        })->filter()->values();

        return [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'summary' => [
                'solicitudes_en_periodo' => $createdInRange->count(),
                'confirmaciones_en_periodo' => $confirmedInRange->count(),
                'solicitudes_sin_confirmar_creadas_en_periodo' => $solicitudesSinConfirmar,
            ],
            'tiempos' => [
                'horas_promedio_hasta_confirmacion' => $this->averageHours($horasHastaConfirmacion),
                'horas_mediana_hasta_confirmacion' => $this->medianHours($horasHastaConfirmacion),
                'horas_promedio_solicitud_a_ultima_actualizacion' => $this->averageHours($horasSolicitudAActualizacion),
                'horas_mediana_solicitud_a_ultima_actualizacion' => $this->medianHours($horasSolicitudAActualizacion),
            ],
            'dataPoints' => $dataPoints->all(),
        ];
    }

    private function averageHours(Collection $hours): ?float
    {
        if ($hours->isEmpty()) {
            return null;
        }

        return round($hours->avg(), 1);
    }

    private function medianHours(Collection $hours): ?float
    {
        if ($hours->isEmpty()) {
            return null;
        }

        $sorted = $hours->sort()->values();
        $count = $sorted->count();
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return round((float) $sorted[$mid], 1);
        }

        return round(((float) $sorted[$mid - 1] + (float) $sorted[$mid]) / 2, 1);
    }
}
