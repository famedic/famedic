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
use Illuminate\Support\Facades\Log;
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

        Log::info('laboratory.callback_availability.request_validated', [
            'appointment_id' => $laboratoryAppointment->id,
            'brand' => $laboratoryBrand->value,
            'validated' => [
                'callback_availability_starts_at' => $data['callback_availability_starts_at'] ?? null,
                'callback_availability_ends_at' => $data['callback_availability_ends_at'] ?? null,
                'patient_callback_comment_len' => isset($data['patient_callback_comment'])
                    ? strlen((string) $data['patient_callback_comment'])
                    : 0,
            ],
            'server_now' => now()->toIso8601String(),
        ]);

        $interactionId = null;

        DB::transaction(function () use ($laboratoryAppointment, $data, &$interactionId): void {
            $laboratoryAppointment->update([
                'callback_availability_starts_at' => $data['callback_availability_starts_at'] ?? null,
                'callback_availability_ends_at' => $data['callback_availability_ends_at'] ?? null,
                'patient_callback_comment' => $data['patient_callback_comment'] ?? null,
            ]);

            $interaction = $laboratoryAppointment->interactions()->create([
                'type' => LaboratoryAppointmentInteractionType::PatientCallbackPreference,
                'metadata' => [
                    'callback_availability_starts_at' => $data['callback_availability_starts_at'] ?? null,
                    'callback_availability_ends_at' => $data['callback_availability_ends_at'] ?? null,
                    'patient_callback_comment' => $data['patient_callback_comment'] ?? null,
                ],
            ]);

            $interactionId = $interaction->id;
        });

        $fresh = $laboratoryAppointment->fresh();

        Log::info('laboratory.callback_availability.persisted', [
            'appointment_id' => $laboratoryAppointment->id,
            'interaction_id' => $interactionId,
            'stored' => [
                'callback_availability_starts_at' => $fresh?->callback_availability_starts_at?->toIso8601String(),
                'callback_availability_ends_at' => $fresh?->callback_availability_ends_at?->toIso8601String(),
                'patient_callback_comment' => $fresh?->patient_callback_comment !== null
                    ? '(present, '.strlen((string) $fresh->patient_callback_comment).' chars)'
                    : null,
            ],
        ]);

        return back()->flashMessage('Guardamos tu disponibilidad y comentarios.');
    }
}
