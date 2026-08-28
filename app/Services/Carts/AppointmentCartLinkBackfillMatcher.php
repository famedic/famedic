<?php

namespace App\Services\Carts;

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\LaboratoryAppointment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppointmentCartLinkBackfillMatcher
{
    public const STATUS_ALREADY_LINKED = 'ALREADY_LINKED';

    public const STATUS_MATCHED = 'MATCHED';

    public const STATUS_NO_MATCH = 'NO_MATCH';

    public const STATUS_AMBIGUOUS = 'AMBIGUOUS';

    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_NONE = 'none';

    private const HIGH_CONFIDENCE_SCORE = 35;

    private const MIN_CANDIDATE_SCORE = 25;

    private const AMBIGUOUS_SCORE_GAP = 10;

    private const PRE_APPOINTMENT_WINDOW_HOURS = 48;

    private const MAX_CART_AGE_BEFORE_APPOINTMENT_HOURS = 24;

    private const POST_CART_CREATION_GRACE_MINUTES = 5;

    /**
     * @return array{
     *     appointment_id: int,
     *     customer_id: int|null,
     *     brand: string|null,
     *     appointment_created_at: string|null,
     *     candidate_cart_id: int|null,
     *     confidence: string,
     *     reason: string,
     *     action: string,
     *     score: int|null,
     *     candidates: list<array{cart_id: int, score: int, reason: string}>
     * }
     */
    public function assess(LaboratoryAppointment $appointment, ?int $forcedCartId = null): array
    {
        $base = [
            'appointment_id' => (int) $appointment->id,
            'customer_id' => $appointment->customer_id ? (int) $appointment->customer_id : null,
            'brand' => $appointment->brand instanceof LaboratoryBrand
                ? $appointment->brand->value
                : (string) ($appointment->brand ?? ''),
            'appointment_created_at' => $appointment->created_at?->toIso8601String(),
            'candidate_cart_id' => null,
            'confidence' => self::CONFIDENCE_NONE,
            'reason' => '',
            'action' => self::STATUS_NO_MATCH,
            'score' => null,
            'candidates' => [],
        ];

        if ($appointment->cart_id !== null) {
            return array_merge($base, [
                'candidate_cart_id' => (int) $appointment->cart_id,
                'reason' => 'La cita ya tiene cart_id explícito.',
                'action' => self::STATUS_ALREADY_LINKED,
            ]);
        }

        if (! $appointment->customer_id || ! $appointment->brand || ! $appointment->created_at) {
            return array_merge($base, [
                'reason' => 'Faltan customer_id, brand o created_at para evaluar evidencia.',
            ]);
        }

        if (! Schema::hasColumn('laboratory_appointments', 'cart_id')) {
            return array_merge($base, [
                'reason' => 'La columna laboratory_appointments.cart_id no existe.',
            ]);
        }

        $candidates = $this->scoreCandidates($appointment, $forcedCartId)
            ->sortByDesc('score')
            ->values();

        $base['candidates'] = $candidates
            ->map(fn (array $row) => [
                'cart_id' => $row['cart_id'],
                'score' => $row['score'],
                'reason' => $row['reason'],
            ])
            ->all();

        if ($candidates->isEmpty()) {
            return array_merge($base, [
                'reason' => $forcedCartId !== null
                    ? "Cart #{$forcedCartId} no cumple evidencia mínima para la cita."
                    : 'Sin cart candidato con customer, brand y ventana temporal coherentes.',
            ]);
        }

        $top = $candidates->first();
        $runnerUp = $candidates->skip(1)->first();

        if ($runnerUp !== null
            && (int) $runnerUp['score'] >= self::MIN_CANDIDATE_SCORE
            && ((int) $top['score'] - (int) $runnerUp['score']) < self::AMBIGUOUS_SCORE_GAP) {
            return array_merge($base, [
                'reason' => sprintf(
                    'Múltiples carts con evidencia similar (#%d=%d vs #%d=%d).',
                    $top['cart_id'],
                    $top['score'],
                    $runnerUp['cart_id'],
                    $runnerUp['score'],
                ),
                'action' => self::STATUS_AMBIGUOUS,
            ]);
        }

        if ((int) $top['score'] < self::HIGH_CONFIDENCE_SCORE) {
            return array_merge($base, [
                'candidate_cart_id' => (int) $top['cart_id'],
                'score' => (int) $top['score'],
                'reason' => "Evidencia insuficiente (score {$top['score']} < ".self::HIGH_CONFIDENCE_SCORE.').',
            ]);
        }

        return array_merge($base, [
            'candidate_cart_id' => (int) $top['cart_id'],
            'confidence' => self::CONFIDENCE_HIGH,
            'reason' => $top['reason'],
            'action' => self::STATUS_MATCHED,
            'score' => (int) $top['score'],
        ]);
    }

    /**
     * @return Collection<int, array{cart_id: int, score: int, reason: string}>
     */
    private function scoreCandidates(LaboratoryAppointment $appointment, ?int $forcedCartId): Collection
    {
        $brand = $appointment->brand instanceof LaboratoryBrand
            ? $appointment->brand->value
            : (string) $appointment->brand;
        $appointmentAt = $appointment->created_at;

        $query = Cart::query()
            ->select('carts.*')
            ->join('customers', 'customers.user_id', '=', 'carts.user_id')
            ->where('customers.id', $appointment->customer_id)
            ->where('carts.type', MonitoringCartType::Lab->value)
            ->where('carts.created_at', '<=', $appointmentAt->copy()->addMinutes(self::POST_CART_CREATION_GRACE_MINUTES))
            ->where('carts.created_at', '>=', $appointmentAt->copy()->subHours(self::MAX_CART_AGE_BEFORE_APPOINTMENT_HOURS))
            ->where(function ($window) use ($appointmentAt) {
                $window->where('carts.updated_at', '>=', $appointmentAt->copy()->subHours(self::PRE_APPOINTMENT_WINDOW_HOURS))
                    ->orWhere('carts.completed_at', '>=', $appointmentAt->copy()->subHours(self::PRE_APPOINTMENT_WINDOW_HOURS));
            })
            ->whereExists(function ($sub) use ($brand) {
                $sub->selectRaw('1')
                    ->from('cart_items')
                    ->join('laboratory_tests', fn ($join) => $join->whereRaw('laboratory_tests.id = cart_items.product_id'))
                    ->whereColumn('cart_items.cart_id', 'carts.id')
                    ->where('laboratory_tests.brand', $brand);
            })
            ->when($forcedCartId !== null, fn ($q) => $q->where('carts.id', $forcedCartId));

        /** @var Collection<int, Cart> $carts */
        $carts = $query->with(['events'])->get();

        return $carts
            ->reject(fn (Cart $cart) => $this->cartHasConflictingLinkedAppointment($cart, $appointment, $brand))
            ->map(fn (Cart $cart) => $this->scoreCart($cart, $appointmentAt))
            ->filter(fn (array $row) => $row['score'] > 0)
            ->values();
    }

    private function cartHasConflictingLinkedAppointment(Cart $cart, LaboratoryAppointment $appointment, string $brand): bool
    {
        return LaboratoryAppointment::query()
            ->where('cart_id', $cart->id)
            ->where('id', '!=', $appointment->id)
            ->where('brand', $brand)
            ->exists();
    }

    /**
     * @return array{cart_id: int, score: int, reason: string}
     */
    private function scoreCart(Cart $cart, CarbonInterface $appointmentAt): array
    {
        $score = 0;
        $reasons = [];

        if ($cart->status === MonitoringCartStatus::Active) {
            $score += 5;
            $reasons[] = 'cart activo';
        }

        $minutesFromUpdate = abs($cart->updated_at?->diffInMinutes($appointmentAt) ?? 999);
        if ($minutesFromUpdate <= 15) {
            $score += 20;
            $reasons[] = 'actividad cart ≤15 min';
        } elseif ($minutesFromUpdate <= 60) {
            $score += 10;
            $reasons[] = 'actividad cart ≤60 min';
        } elseif ($minutesFromUpdate <= 180) {
            $score += 5;
            $reasons[] = 'actividad cart ≤180 min';
        }

        $events = $cart->relationLoaded('events')
            ? $cart->events
            : $cart->events()->get();

        if ($this->hasCheckoutEventNear($events, CartEventType::PatientSelected, $appointmentAt)) {
            $score += 30;
            $reasons[] = 'patient_selected';
        }

        if ($this->hasCheckoutEventNear($events, CartEventType::AddressSelected, $appointmentAt)) {
            $score += 25;
            $reasons[] = 'address_selected';
        }

        if ($this->hasCheckoutEventNear($events, CartEventType::CheckoutStarted, $appointmentAt)) {
            $score += 10;
            $reasons[] = 'checkout_started';
        }

        if ($this->hasCheckoutEventNear($events, CartEventType::PaymentMethodSelected, $appointmentAt)) {
            $score += 10;
            $reasons[] = 'payment_method_selected';
        }

        return [
            'cart_id' => (int) $cart->id,
            'score' => $score,
            'reason' => $reasons !== [] ? implode(', ', $reasons) : 'solo ventana temporal',
        ];
    }

    /**
     * @param  Collection<int, CartEvent>  $events
     */
    private function hasCheckoutEventNear(Collection $events, CartEventType $type, CarbonInterface $appointmentAt): bool
    {
        return $events->contains(function (CartEvent $event) use ($type, $appointmentAt) {
            $eventValue = $event->event instanceof CartEventType ? $event->event->value : (string) $event->event;
            if ($eventValue !== $type->value || ! $event->occurred_at) {
                return false;
            }

            return $event->occurred_at->lte($appointmentAt->copy()->addMinutes(self::POST_CART_CREATION_GRACE_MINUTES))
                && $event->occurred_at->gte($appointmentAt->copy()->subHours(4));
        });
    }
}
