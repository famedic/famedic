<?php

namespace App\Services\Carts;

use App\Enums\CartCheckoutFlowType;
use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\CartEvent;
use App\Models\Customer;
use App\Services\Laboratory\LaboratoryCheckoutFlowEligibility;

/**
 * Fuente durable del tipo de flujo de checkout por carrito y marca.
 *
 * Persiste en cart_events (metadata local). No genera dispatch ActiveCampaign.
 */
class CartCheckoutFlowResolver
{
    public function __construct(
        private CartEventRecorder $cartEventRecorder,
        private LaboratoryCheckoutFlowEligibility $flowEligibility,
    ) {}

    /**
     * Registra el flujo determinado en el primer checkout (idempotente por carrito+marca).
     */
    public function recordDeterminedFlow(Cart $cart, Customer $customer, LaboratoryBrand $brand): void
    {
        if ($cart->type !== MonitoringCartType::Lab) {
            return;
        }

        if (! $customer->getHasLaboratoryCartItemRequiringAppointment($brand)) {
            $flow = CartCheckoutFlowType::Standard;
        } else {
            $flow = $this->flowEligibility->usesAppointmentFirstFlow($customer, $brand)
                ? CartCheckoutFlowType::AppointmentFirst
                : CartCheckoutFlowType::Standard;
        }

        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CheckoutFlowDetermined,
            "cart:{$cart->id}:checkout_flow:{$brand->value}",
            [
                'checkout_flow' => $flow->value,
                'brand' => $brand->value,
            ],
            source: 'laboratory_checkout',
        );
    }

    /**
     * @return array{
     *     flow: CartCheckoutFlowType,
     *     confidence: 'stored'|'inferred'|'eligibility_fallback'|'unknown',
     *     label: string,
     *     brand: string|null,
     * }
     */
    public function resolve(Cart $cart, ?LaboratoryBrand $brand = null): array
    {
        if ($cart->type !== MonitoringCartType::Lab) {
            return $this->result(CartCheckoutFlowType::Standard, 'stored', null);
        }

        $brand ??= $this->primaryBrand($cart);
        if ($brand === null) {
            return $this->result(CartCheckoutFlowType::Unknown, 'unknown', null);
        }

        $stored = $this->storedFlow($cart, $brand);
        if ($stored !== null) {
            return $this->result($stored, 'stored', $brand->value);
        }

        $inferred = $this->inferFromEventOrder($cart, $brand);
        if ($inferred !== null) {
            return $this->result($inferred, 'inferred', $brand->value);
        }

        if ($cart->status === MonitoringCartStatus::Active && $this->cartHasNotAdvanced($cart)) {
            $customer = $cart->user?->customer;
            if ($customer !== null) {
                $fallback = $customer->getHasLaboratoryCartItemRequiringAppointment($brand)
                    && $this->flowEligibility->usesAppointmentFirstFlow($customer, $brand)
                    ? CartCheckoutFlowType::AppointmentFirst
                    : CartCheckoutFlowType::Standard;

                return $this->result($fallback, 'eligibility_fallback', $brand->value);
            }
        }

        return $this->result(CartCheckoutFlowType::Unknown, 'unknown', $brand->value);
    }

    public function resolveForAppointment(Cart $cart, LaboratoryBrand $brand): CartCheckoutFlowType
    {
        return $this->resolve($cart, $brand)['flow'];
    }

    private function storedFlow(Cart $cart, LaboratoryBrand $brand): ?CartCheckoutFlowType
    {
        $events = $cart->relationLoaded('events')
            ? $cart->events
            : $cart->events()->orderBy('occurred_at')->orderBy('id')->get();

        foreach ($events as $event) {
            $eventValue = $event->event?->value ?? (string) $event->event;
            if ($eventValue === CartEventType::CheckoutFlowDetermined->value) {
                $metadataBrand = $event->metadata['brand'] ?? null;
                if ($metadataBrand !== null && $metadataBrand !== $brand->value) {
                    continue;
                }

                return CartCheckoutFlowType::tryFrom((string) ($event->metadata['checkout_flow'] ?? ''));
            }

            if ($eventValue === CartEventType::CheckoutStarted->value) {
                $flow = CartCheckoutFlowType::tryFrom((string) ($event->metadata['checkout_flow'] ?? ''));
                if ($flow !== null) {
                    $metadataBrand = $event->metadata['brand'] ?? null;
                    if ($metadataBrand === null || $metadataBrand === $brand->value) {
                        return $flow;
                    }
                }
            }
        }

        return null;
    }

    private function inferFromEventOrder(Cart $cart, LaboratoryBrand $brand): ?CartCheckoutFlowType
    {
        $events = $cart->relationLoaded('events')
            ? $cart->events
            : $cart->events()->orderBy('occurred_at')->orderBy('id')->get();

        $appointmentAt = $this->firstEventAt($events, CartEventType::AppointmentRequested, $brand);
        $paymentAt = $this->firstEventAt($events, CartEventType::PaymentMethodSelected, $brand);

        if ($appointmentAt === null && $paymentAt === null) {
            return null;
        }

        if ($appointmentAt !== null && $paymentAt === null) {
            return CartCheckoutFlowType::AppointmentFirst;
        }

        if ($paymentAt !== null && $appointmentAt === null) {
            return CartCheckoutFlowType::Standard;
        }

        if ($appointmentAt->lt($paymentAt)) {
            return CartCheckoutFlowType::AppointmentFirst;
        }

        if ($paymentAt->lt($appointmentAt)) {
            return CartCheckoutFlowType::Standard;
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CartEvent>  $events
     */
    private function firstEventAt($events, CartEventType $type, LaboratoryBrand $brand): ?\Carbon\CarbonInterface
    {
        return $events
            ->first(function (CartEvent $event) use ($type, $brand) {
                $eventValue = $event->event?->value ?? (string) $event->event;
                if ($eventValue !== $type->value) {
                    return false;
                }

                $metadataBrand = $event->metadata['brand'] ?? null;

                return $metadataBrand === null || $metadataBrand === $brand->value;
            })
            ?->occurred_at;
    }

    private function cartHasNotAdvanced(Cart $cart): bool
    {
        $events = $cart->relationLoaded('events')
            ? $cart->events
            : $cart->events()->get();

        $advanced = [
            CartEventType::PatientSelected->value,
            CartEventType::AddressSelected->value,
            CartEventType::PaymentMethodSelected->value,
            CartEventType::AppointmentRequested->value,
            CartEventType::PaymentStarted->value,
        ];

        return ! $events->contains(fn (CartEvent $event) => in_array(
            $event->event?->value ?? (string) $event->event,
            $advanced,
            true,
        ));
    }

    private function primaryBrand(Cart $cart): ?LaboratoryBrand
    {
        $brands = collect($cart->labBrands())->pluck('value')->filter()->unique()->values();

        if ($brands->count() === 1) {
            return LaboratoryBrand::tryFrom((string) $brands->first());
        }

        if ($brands->isEmpty()) {
            return null;
        }

        return LaboratoryBrand::tryFrom((string) $brands->first());
    }

    /**
     * @return array{flow: CartCheckoutFlowType, confidence: string, label: string, brand: string|null}
     */
    private function result(CartCheckoutFlowType $flow, string $confidence, ?string $brand): array
    {
        return [
            'flow' => $flow,
            'confidence' => $confidence,
            'label' => $flow->label(),
            'brand' => $brand,
        ];
    }
}
