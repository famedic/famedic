<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LaboratoryBrand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryAppointments\IndexLaboratoryAppointmentMetricsRequest;
use App\Models\LaboratoryAppointment;
use Carbon\Carbon;
use Inertia\Inertia;

class LaboratoryAppointmentMetricsController extends Controller
{
    public function __invoke(IndexLaboratoryAppointmentMetricsRequest $request)
    {
        $start = Carbon::parse($request->input('start_date', now()->subMonths(2)->format('Y-m-d')))->startOfDay();
        $end = Carbon::parse($request->input('end_date', now()->format('Y-m-d')))->endOfDay();

        $appointments = LaboratoryAppointment::query()
            ->whereBetween('created_at', [$start, $end])
            ->get([
                'id',
                'created_at',
                'confirmed_at',
                'brand',
                'phone_call_intent_at',
                'callback_availability_starts_at',
                'callback_availability_ends_at',
                'patient_callback_comment',
            ]);

        $requestedVsConfirmed = $appointments
            ->groupBy(fn (LaboratoryAppointment $a) => $a->created_at->format('Y-m'))
            ->map(function ($group, string $ym) {
                $c = Carbon::parse($ym.'-01');

                return [
                    'period' => $ym,
                    'label' => $c->locale('es')->isoFormat('MMM YYYY'),
                    'solicitudes' => $group->count(),
                    'confirmadas' => $group->whereNotNull('confirmed_at')->count(),
                ];
            })
            ->sortKeys()
            ->values();

        $byBrand = collect(LaboratoryBrand::cases())->map(function (LaboratoryBrand $brand) use ($appointments) {
            $subset = $appointments->filter(fn (LaboratoryAppointment $a) => $a->brand === $brand);

            return [
                'brand' => $brand->value,
                'label' => $brand->label(),
                'total' => $subset->count(),
                'confirmadas' => $subset->whereNotNull('confirmed_at')->count(),
            ];
        })->values();

        $confirmedWithTimes = $appointments->whereNotNull('confirmed_at');
        $avgSeconds = $confirmedWithTimes->isEmpty()
            ? null
            : $confirmedWithTimes->avg(fn (LaboratoryAppointment $a) => $a->created_at->diffInSeconds($a->confirmed_at));

        $avgHours = $avgSeconds !== null ? round($avgSeconds / 3600, 1) : null;

        $withIntent = $appointments->whereNotNull('phone_call_intent_at')->count();

        $withCallbackPrefs = $appointments->filter(fn (LaboratoryAppointment $a) => $a->callback_availability_starts_at !== null
            || $a->callback_availability_ends_at !== null
            || filled($a->patient_callback_comment))->count();

        return Inertia::render('Admin/LaboratoryAppointmentMetrics', [
            'filters' => [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
            ],
            'summary' => [
                'total_solicitudes' => $appointments->count(),
                'total_confirmadas' => $appointments->whereNotNull('confirmed_at')->count(),
                'promedio_horas_solicitud_a_confirmacion' => $avgHours,
                'pacientes_con_intento_llamada' => $withIntent,
                'pacientes_con_disponibilidad_o_comentario' => $withCallbackPrefs,
            ],
            'requestedVsConfirmed' => $requestedVsConfirmed,
            'byBrand' => $byBrand,
        ]);
    }
}
