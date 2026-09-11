<?php

namespace App\Services\Carts;

use App\Enums\CartEventType;
use App\Enums\MonitoringCartStatus;
use App\Models\CartEvent;
use App\Models\LaboratoryAppointment;
use App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher;
use Illuminate\Support\Collection;

class AppointmentPendingDetectionService
{
    public function __construct(
        private CartEventRecorder $cartEventRecorder,
        private ActiveCampaignOutboundDispatcher $activeCampaignOutboundDispatcher,
    ) {}

    public function pendingAfterMinutes(): int
    {
        return max(1, (int) config('carts.appointment_pending_after_minutes', 5));
    }

    public function isEligible(LaboratoryAppointment $appointment): bool
    {
        if ($appointment->confirmed_at !== null) {
            return false;
        }

        if ($appointment->trashed()) {
            return false;
        }

        $appointment->loadMissing(['cart.items']);
        $cart = $appointment->cart;

        if ($cart === null || $cart->status !== MonitoringCartStatus::Active) {
            return false;
        }

        if ($cart->isEmptyActiveMonitoringCart() || ! $cart->items()->exists()) {
            return false;
        }

        $createdAt = $appointment->created_at;
        if ($createdAt === null || $createdAt->gt(now()->subMinutes($this->pendingAfterMinutes()))) {
            return false;
        }

        return ! $this->hasPendingEvent($appointment);
    }

    /**
     * @return Collection<int, LaboratoryAppointment>
     */
    public function appointmentsEligibleForDetection(): Collection
    {
        $threshold = now()->subMinutes($this->pendingAfterMinutes());

        return LaboratoryAppointment::query()
            ->whereNull('confirmed_at')
            ->whereNotNull('cart_id')
            ->where('created_at', '<=', $threshold)
            ->whereHas('cart', function ($query) {
                $query
                    ->where('status', MonitoringCartStatus::Active)
                    ->whereHas('items');
            })
            ->with(['cart.items', 'cart.user.customer'])
            ->orderBy('id')
            ->get()
            ->reject(fn (LaboratoryAppointment $appointment) => $this->hasPendingEvent($appointment))
            ->values();
    }

    public function detectAndRecord(LaboratoryAppointment $appointment): ?CartEvent
    {
        $appointment->refresh();
        $appointment->loadMissing(['cart.items']);

        if (! $this->isEligible($appointment)) {
            return null;
        }

        $cart = $appointment->cart;
        if ($cart === null) {
            return null;
        }

        $minutesPending = max(
            $this->pendingAfterMinutes(),
            (int) floor($appointment->created_at?->diffInMinutes(now()) ?? $this->pendingAfterMinutes()),
        );

        $event = $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::AppointmentPending5m,
            "appointment:{$appointment->id}:pending_5m",
            [
                'appointment_id' => (int) $appointment->id,
                'cart_id' => (int) $cart->id,
                'brand' => $appointment->brand?->value,
                'requested_at' => $appointment->created_at?->toIso8601String(),
                'detected_at' => now()->toIso8601String(),
                'minutes_pending' => $minutesPending,
            ],
            now(),
            'appointment_pending_detector',
        );

        if ($event instanceof CartEvent) {
            $this->activeCampaignOutboundDispatcher->enqueueFromCartEvent($cart, $event);
        }

        return $event;
    }

    public function hasPendingEvent(LaboratoryAppointment $appointment): bool
    {
        if ($appointment->cart_id === null) {
            return false;
        }

        return CartEvent::query()
            ->where('cart_id', $appointment->cart_id)
            ->where('event', CartEventType::AppointmentPending5m->value)
            ->where('idempotency_key', "appointment:{$appointment->id}:pending_5m")
            ->exists();
    }
}
