<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CreateLaboratoryAppointmentAction;
use App\Enums\LaboratoryAppointmentInteractionType;
use App\Enums\LaboratoryBrand;
use App\Http\Requests\LaboratoryAppointments\RecordLaboratoryAppointmentPhoneIntentRequest;
use App\Http\Requests\LaboratoryAppointments\UpdateLaboratoryAppointmentCallbackAvailabilityRequest;
use App\Models\LaboratoryAppointment;
use App\Services\Carts\CartAppointmentContactSignalService;
use App\Services\Monitoring\SyncMonitoringCartService;
use App\Support\ClientContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class LaboratoryAppointmentController extends Controller
{
    public function __construct(
        private SyncMonitoringCartService $syncMonitoringCartService,
        private CartAppointmentContactSignalService $cartAppointmentContactSignalService,
    ) {
    }

    public function create(Request $request, LaboratoryBrand $laboratoryBrand)
    {
        return Inertia::render('LaboratoryAppointmentCreation', [
            'laboratoryBrand' => $laboratoryBrand,
        ]);
    }

    public function store(Request $request, LaboratoryBrand $laboratoryBrand, CreateLaboratoryAppointmentAction $action)
    {
        $laboratoryAppointment = $action($request->user()->customer, $laboratoryBrand, ClientContext::fromRequest($request));

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
            ?->timezone('America/Monterrey')
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
        $validated = $request->validated();
        $channel = $validated['channel'];
        $interactionType = $channel === 'whatsapp'
            ? LaboratoryAppointmentInteractionType::PatientWhatsAppIntent
            : LaboratoryAppointmentInteractionType::PatientPhoneIntent;

        $interactionId = null;
        $metadata = $this->contactIntentMetadata(
            $request,
            $laboratoryAppointment,
            $validated,
            $channel,
        );

        DB::transaction(function () use ($laboratoryAppointment, $interactionType, $metadata, $channel, &$interactionId): void {
            $lockedAppointment = LaboratoryAppointment::query()
                ->whereKey($laboratoryAppointment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $recentInteraction = $lockedAppointment->interactions()
                ->where('type', $interactionType->value)
                ->where('created_at', '>=', now()->subSeconds(30))
                ->exists();

            if ($recentInteraction) {
                return;
            }

            if ($channel === 'phone') {
                $lockedAppointment->update(['phone_call_intent_at' => now()]);
            }

            $interaction = $lockedAppointment->interactions()->create([
                'type' => $interactionType,
                'metadata' => $metadata,
            ]);
            $interactionId = $interaction->id;
        });

        if ($interactionId !== null) {
            $this->cartAppointmentContactSignalService->recordCallAttempted(
                $laboratoryAppointment->fresh(['cart']),
                $interactionId,
                channel: $channel,
                metadata: $metadata,
            );
        }

        $this->syncMonitoringCartService->touchLaboratoryCartActivity($laboratoryAppointment->customer);

        return $request->expectsJson() ? response()->noContent() : back();
    }

    public function updateCallbackAvailability(
        UpdateLaboratoryAppointmentCallbackAvailabilityRequest $request,
        LaboratoryBrand $laboratoryBrand,
        LaboratoryAppointment $laboratoryAppointment
    ) {
        $data = $request->parsedCallbackAvailability();

        if (! $this->callbackAvailabilityChanged($laboratoryAppointment, $data)) {
            return back()->flashMessage('Guardamos tu disponibilidad y comentarios.');
        }

        Log::info('laboratory.callback_availability.request_validated', [
            'appointment_id' => $laboratoryAppointment->id,
            'brand' => $laboratoryBrand->value,
            'validated' => [
                'callback_availability_starts_at' => $data['callback_availability_starts_at']?->toIso8601String(),
                'callback_availability_ends_at' => $data['callback_availability_ends_at']?->toIso8601String(),
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
                    'callback_availability_starts_at' => $data['callback_availability_starts_at']?->toIso8601String(),
                    'callback_availability_ends_at' => $data['callback_availability_ends_at']?->toIso8601String(),
                    'patient_callback_comment' => $data['patient_callback_comment'] ?? null,
                ],
            ]);

            $interactionId = $interaction->id;
        });

        if ($interactionId !== null) {
            $this->cartAppointmentContactSignalService->recordCallRequested(
                $laboratoryAppointment->fresh(['cart']),
                $interactionId,
                ($data['callback_availability_starts_at'] ?? null) !== null
                    || ($data['callback_availability_ends_at'] ?? null) !== null,
            );
        }

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

        $this->syncMonitoringCartService->touchLaboratoryCartActivity($laboratoryAppointment->customer);

        return back()->flashMessage('Guardamos tu disponibilidad y comentarios.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function callbackAvailabilityChanged(LaboratoryAppointment $appointment, array $data): bool
    {
        return ! $this->sameInstant($appointment->callback_availability_starts_at, $data['callback_availability_starts_at'] ?? null)
            || ! $this->sameInstant($appointment->callback_availability_ends_at, $data['callback_availability_ends_at'] ?? null)
            || (string) ($appointment->patient_callback_comment ?? '') !== (string) ($data['patient_callback_comment'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function contactIntentMetadata(
        Request $request,
        LaboratoryAppointment $laboratoryAppointment,
        array $validated,
        string $channel,
    ): array {
        $customer = $laboratoryAppointment->customer;
        $addressId = isset($validated['address_id'])
            ? $customer?->addresses()->whereKey((int) $validated['address_id'])->value('id')
            : null;
        $contactId = isset($validated['contact_id'])
            ? $customer?->contacts()->whereKey((int) $validated['contact_id'])->value('id')
            : null;

        return array_filter([
            'channel' => $channel,
            'context' => $validated['context'] ?? 'laboratory_checkout',
            'step' => $validated['step'] ?? 'appointment',
            'address_id' => $addressId ? (int) $addressId : null,
            'contact_id' => $contactId ? (int) $contactId : null,
            'current_url' => $validated['current_url'] ?? $request->headers->get('referer'),
            'appointment_id' => (int) $laboratoryAppointment->id,
            'cart_id' => $laboratoryAppointment->cart_id ? (int) $laboratoryAppointment->cart_id : null,
            'brand' => $laboratoryAppointment->brand?->value,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function sameInstant(mixed $left, mixed $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }

        if ($left === null || $right === null) {
            return false;
        }

        return $left->eq($right);
    }
}
