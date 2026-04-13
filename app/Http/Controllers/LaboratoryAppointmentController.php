<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CreateLaboratoryAppointmentAction;
use App\Enums\LaboratoryAppointmentInteractionType;
use App\Enums\LaboratoryBrand;
use App\Http\Requests\LaboratoryAppointments\RecordLaboratoryAppointmentPhoneIntentRequest;
use App\Http\Requests\LaboratoryAppointments\UpdateLaboratoryAppointmentCallbackAvailabilityRequest;
use App\Models\LaboratoryAppointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'laboratory_appointment' => $laboratoryAppointment,
        ])
            ->flashMessage('Se ha enviado un nuevo pedido de cita.');
    }

    public function show(Request $request, LaboratoryBrand $laboratoryBrand, LaboratoryAppointment $laboratoryAppointment)
    {
        $lastPreferenceInteraction = $laboratoryAppointment->interactions()
            ->where('type', LaboratoryAppointmentInteractionType::PatientCallbackPreference->value)
            ->latest('id')
            ->first();

        $callbackPreferenceSavedAtFormatted = $lastPreferenceInteraction?->created_at
            ?->timezone(config('app.timezone'))
            ?->locale('es')
            ?->isoFormat('dddd D [de] MMMM [de] YYYY, h:mm a');

        return Inertia::render('LaboratoryAppointment', [
            'laboratoryAppointment' => $laboratoryAppointment,
            'callbackPreferenceSavedAtFormatted' => $callbackPreferenceSavedAtFormatted,
        ]);
    }

    public function recordPhoneIntent(
        RecordLaboratoryAppointmentPhoneIntentRequest $request,
        LaboratoryBrand $laboratoryBrand,
        LaboratoryAppointment $laboratoryAppointment
    ) {
        DB::transaction(function () use ($laboratoryAppointment): void {
            $laboratoryAppointment->update(['phone_call_intent_at' => now()]);
            $laboratoryAppointment->interactions()->create([
                'type' => LaboratoryAppointmentInteractionType::PatientPhoneIntent,
            ]);
        });

        return back();
    }

    public function updateCallbackAvailability(
        UpdateLaboratoryAppointmentCallbackAvailabilityRequest $request,
        LaboratoryBrand $laboratoryBrand,
        LaboratoryAppointment $laboratoryAppointment
    ) {
        $data = $request->validated();

        DB::transaction(function () use ($laboratoryAppointment, $data): void {
            $laboratoryAppointment->update([
                'callback_availability_starts_at' => $data['callback_availability_starts_at'] ?? null,
                'callback_availability_ends_at' => $data['callback_availability_ends_at'] ?? null,
                'patient_callback_comment' => $data['patient_callback_comment'] ?? null,
            ]);
            $laboratoryAppointment->interactions()->create([
                'type' => LaboratoryAppointmentInteractionType::PatientCallbackPreference,
                'metadata' => [
                    'callback_availability_starts_at' => $data['callback_availability_starts_at'] ?? null,
                    'callback_availability_ends_at' => $data['callback_availability_ends_at'] ?? null,
                    'patient_callback_comment' => $data['patient_callback_comment'] ?? null,
                ],
            ]);
        });

        return back()->flashMessage('Guardamos tu disponibilidad y comentarios.');
    }
}
