<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CreateLaboratoryAppointmentAction;
use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryAppointment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LaboratoryAppointmentController extends Controller
{
    public function create(Request $request, LaboratoryBrand $laboratoryBrand)
    {
        return Inertia::render('LaboratoryAppointmentCreation', [
            'laboratoryBrand' => $laboratoryBrand,
        ]);
    }

    public function store(Request $request, LaboratoryBrand $laboratoryBrand, CreateLaboratoryAppointmentAction $action)
    {
        $laboratoryAppointment = $action($request->user()->customer, $laboratoryBrand);

        return redirect()->route('laboratory-appointments.show', [
            'laboratory_brand' => $laboratoryBrand,
            'laboratory_appointment' => $laboratoryAppointment
        ])
            ->flashMessage('Se ha enviado un nuevo pedido de cita.');
    }

    public function show(Request $request, LaboratoryBrand $laboratoryBrand, LaboratoryAppointment $laboratoryAppointment)
    {
        return Inertia::render('LaboratoryAppointment', [
            'laboratoryAppointment' => $laboratoryAppointment,
        ]);
    }
}
