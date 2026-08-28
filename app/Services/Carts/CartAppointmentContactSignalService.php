<?php

namespace App\Services\Carts;

use App\Enums\CartEventType;
use App\Models\CartEvent;
use App\Models\LaboratoryAppointment;
use App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher;
use Carbon\CarbonInterface;

/**
 * Señales ActiveCampaign de contacto/cita ligadas a cart_events.
 *
 * Semántica Marketing (Fase 4):
 * - TAG (call.requested, call.attempted): el contacto alguna vez realizó esa acción; no se eliminan en esta fase.
 * - EVENT (famedic_call_requested, famedic_call_attempted): cada ocurrencia individual vía cart_events/outbox.
 *   No usar esos tags como "estado actual" sin definir cleanup explícito.
 */
class CartAppointmentContactSignalService
{
    public function __construct(
        private CartEventRecorder $cartEventRecorder,
        private ActiveCampaignOutboundDispatcher $activeCampaignOutboundDispatcher,
    ) {}

    public function recordCallRequested(
        LaboratoryAppointment $appointment,
        int $interactionId,
        bool $hasCallbackAvailability,
        ?CarbonInterface $occurredAt = null,
    ): ?CartEvent {
        $cart = $appointment->cart;
        if ($cart === null) {
            return null;
        }

        $occurredAt ??= now();

        $event = $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CallRequested,
            "appointment_interaction:{$interactionId}:call_requested",
            [
                'appointment_id' => (int) $appointment->id,
                'cart_id' => (int) $cart->id,
                'brand' => $appointment->brand?->value,
                'occurred_at' => $occurredAt->toIso8601String(),
                'has_callback_availability' => $hasCallbackAvailability,
                'interaction_id' => $interactionId,
            ],
            $occurredAt,
            'laboratory_appointment_callback',
        );

        if ($event instanceof CartEvent) {
            $this->activeCampaignOutboundDispatcher->enqueueFromCartEvent($cart, $event);
        }

        return $event;
    }

    public function recordCallAttempted(
        LaboratoryAppointment $appointment,
        int $interactionId,
        ?CarbonInterface $occurredAt = null,
    ): ?CartEvent {
        $cart = $appointment->cart;
        if ($cart === null) {
            return null;
        }

        $occurredAt ??= now();

        $event = $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CallAttempted,
            "appointment_interaction:{$interactionId}:call_attempted",
            [
                'appointment_id' => (int) $appointment->id,
                'cart_id' => (int) $cart->id,
                'brand' => $appointment->brand?->value,
                'occurred_at' => $occurredAt->toIso8601String(),
                'interaction_id' => $interactionId,
            ],
            $occurredAt,
            'laboratory_appointment_phone_intent',
        );

        if ($event instanceof CartEvent) {
            $this->activeCampaignOutboundDispatcher->enqueueFromCartEvent($cart, $event);
        }

        return $event;
    }
}
