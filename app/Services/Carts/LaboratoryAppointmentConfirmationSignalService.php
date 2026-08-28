<?php

namespace App\Services\Carts;

use App\Enums\CartEventType;
use App\Models\CartEvent;
use App\Models\LaboratoryAppointment;
use App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher;

/**
 * Reacción centralizada a la transición confirmed_at: null → timestamp.
 *
 * Un único path de producción escribe confirmed_at (UpdateLaboratoryAppointmentAction);
 * este servicio se invoca desde LaboratoryAppointmentObserver para no duplicar lógica
 * en controllers/actions y garantizar idempotencia vía recordOnce.
 */
class LaboratoryAppointmentConfirmationSignalService
{
    public function __construct(
        private CartEventRecorder $cartEventRecorder,
        private ActiveCampaignOutboundDispatcher $activeCampaignOutboundDispatcher,
    ) {}

    public function handleNewlyConfirmed(LaboratoryAppointment $appointment): ?CartEvent
    {
        $appointment->loadMissing('cart');
        $cart = $appointment->cart;

        if ($cart === null) {
            return null;
        }

        $event = $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::AppointmentConfirmed,
            "laboratory_appointment:{$appointment->id}:confirmed",
            [
                'laboratory_appointment_id' => $appointment->id,
                'appointment_id' => $appointment->id,
                'cart_id' => $appointment->cart_id,
                'brand' => $appointment->brand?->value,
                'confirmed_at' => $appointment->confirmed_at?->toIso8601String(),
            ],
            $appointment->confirmed_at,
            'laboratory_appointment_confirmation',
        );

        if ($event instanceof CartEvent) {
            $this->activeCampaignOutboundDispatcher->enqueueFromCartEvent($cart, $event);
        }

        return $event;
    }
}
