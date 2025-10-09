<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\LaboratoryAppointments\UpdateLaboratoryAppointmentAction;
use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LaboratoryAppointments\DestroyLaboratoryAppointmentRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\IndexLaboratoryAppointmentRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\ShowLaboratoryAppointmentRequest;
use App\Http\Requests\Admin\LaboratoryAppointments\UpdateLaboratoryAppointmentRequest;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryStore;
use Carbon\Carbon;
use Inertia\Inertia;

class LaboratoryAppointmentController extends Controller
{
    public function index(IndexLaboratoryAppointmentRequest $request)
    {
        $filters = collect($request->only([
            'search',
            'completed',
        ]))->filter()->all();

        return Inertia::render('Admin/LaboratoryAppointments', [
            'laboratoryAppointments' => LaboratoryAppointment::with(['customer.user', 'laboratoryStore', 'laboratoryPurchase.transactions'])
                ->filter($filters)->latest()->paginate()->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function show(ShowLaboratoryAppointmentRequest $request, LaboratoryAppointment $laboratoryAppointment)
    {
        $laboratoryAppointment->load(['customer.user', 'laboratoryStore']);

        return Inertia::render('Admin/LaboratoryAppointment', [
            'laboratoryAppointment' => $laboratoryAppointment,
            'laboratoryStores' => LaboratoryStore::ofBrand($laboratoryAppointment->brand)->orderBy('name')->get(),
            'laboratoryCartItems' => $laboratoryAppointment->customer->laboratoryCartItems()->with('laboratoryTest')->ofBrand($laboratoryAppointment->brand)->get(),
            'genders' => Gender::casesWithLabels(),
        ]);
    }

    public function update(UpdateLaboratoryAppointmentRequest $request, LaboratoryAppointment $laboratoryAppointment, UpdateLaboratoryAppointmentAction $action)
    {
        $action(
            appointment_date: $request->appointment_date,
            appointment_time: $request->appointment_time,
            patient_name: $request->patient_name,
            patient_paternal_lastname: $request->patient_paternal_lastname,
            patient_maternal_lastname: $request->patient_maternal_lastname,
            patient_birth_date: Carbon::parse($request->patient_birth_date),
            patient_gender: Gender::from($request->patient_gender),
            patient_phone: $request->patient_phone,
            patient_phone_country: $request->patient_phone_country,
            laboratory_store: $request->laboratory_store,
            notes: $request->notes,
            laboratoryAppointment: $laboratoryAppointment
        );

        return redirect()->back()
            ->flashMessage('Cita actualizada exitosamente.');
    }

    public function destroy(DestroyLaboratoryAppointmentRequest $request, LaboratoryAppointment $laboratoryAppointment)
    {
        $laboratoryAppointment->delete();

        return redirect()->route('admin.laboratory-appointments.index')
            ->flashMessage('Cita eliminada exitosamente.');
    }
}
