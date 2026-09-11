<?php

namespace App\Services\Carts;

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\LaboratoryAppointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppointmentCartLinkBackfillApplier
{
    public function __construct(
        private CartEventRecorder $cartEventRecorder,
    ) {}

    /**
     * @param  array{
     *     appointment_id: int,
     *     candidate_cart_id: int|null,
     *     action: string,
     *     confidence: string,
     *     brand: string|null,
     * }  $assessment
     */
    public function apply(array $assessment): bool
    {
        if (($assessment['action'] ?? null) !== AppointmentCartLinkBackfillMatcher::STATUS_MATCHED
            || ($assessment['confidence'] ?? null) !== AppointmentCartLinkBackfillMatcher::CONFIDENCE_HIGH
            || empty($assessment['candidate_cart_id'])) {
            return false;
        }

        return DB::transaction(function () use ($assessment) {
            /** @var LaboratoryAppointment|null $appointment */
            $appointment = LaboratoryAppointment::query()
                ->lockForUpdate()
                ->find($assessment['appointment_id']);

            if (! $appointment || $appointment->cart_id !== null) {
                return false;
            }

            /** @var Cart|null $cart */
            $cart = Cart::query()->lockForUpdate()->find($assessment['candidate_cart_id']);
            if (! $cart) {
                return false;
            }

            $matcher = app(AppointmentCartLinkBackfillMatcher::class);
            $freshAssessment = $matcher->assess($appointment, (int) $cart->id);
            if (($freshAssessment['action'] ?? null) !== AppointmentCartLinkBackfillMatcher::STATUS_MATCHED
                || ($freshAssessment['confidence'] ?? null) !== AppointmentCartLinkBackfillMatcher::CONFIDENCE_HIGH) {
                return false;
            }

            $appointment->forceFill(['cart_id' => $cart->id])->save();

            $this->ensureAppointmentRequestedEvent($appointment, $cart);

            return true;
        });
    }

    private function ensureAppointmentRequestedEvent(LaboratoryAppointment $appointment, Cart $cart): void
    {
        if (! Schema::hasTable('cart_events')) {
            return;
        }

        $brand = $appointment->brand instanceof LaboratoryBrand
            ? $appointment->brand->value
            : (string) ($appointment->brand ?? '');

        $legacyKey = "appointment:{$appointment->id}:requested";
        $existing = CartEvent::query()
            ->where('cart_id', $cart->id)
            ->where('event', CartEventType::AppointmentRequested->value)
            ->where(function ($query) use ($appointment, $legacyKey) {
                $query->where('idempotency_key', $legacyKey)
                    ->orWhere('idempotency_key', "laboratory_appointment:{$appointment->id}:requested")
                    ->orWhere('metadata->appointment_id', $appointment->id)
                    ->orWhere('metadata->laboratory_appointment_id', $appointment->id);
            })
            ->exists();

        if ($existing) {
            return;
        }

        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::AppointmentRequested,
            $legacyKey,
            [
                'appointment_id' => (int) $appointment->id,
                'laboratory_appointment_id' => (int) $appointment->id,
                'brand' => $brand,
                'backfilled' => true,
                'source' => 'legacy_backfill',
            ],
            $appointment->created_at,
            'legacy_backfill',
        );
    }
}
