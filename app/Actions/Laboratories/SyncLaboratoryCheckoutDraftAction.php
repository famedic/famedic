<?php

namespace App\Actions\Laboratories;

use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\LaboratoryCheckoutDraft;
use App\Services\Carts\CartEventRecorder;

class SyncLaboratoryCheckoutDraftAction
{
    public function __construct(
        private CartEventRecorder $cartEventRecorder,
    ) {}

    /**
     * @param  array{
     *     step: string,
     *     contact_id?: int|null,
     *     address_id?: int|null,
     *     payment_method?: string|null,
     *     coupon_id?: int|null,
     *     promo_validation_token?: string|null,
     * }  $payload
     */
    public function __invoke(
        Customer $customer,
        LaboratoryBrand $laboratoryBrand,
        array $payload,
    ): LaboratoryCheckoutDraft {
        $requiresAppointment = $customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand);

        $nextStep = match ($payload['step']) {
            'patient' => 'address',
            'address' => 'payment',
            'payment' => $requiresAppointment ? 'appointment' : 'confirmation',
            default => 'patient',
        };

        $attributes = [
            'checkout_step' => $nextStep,
        ];

        if (array_key_exists('contact_id', $payload) && $payload['contact_id'] !== null) {
            $attributes['contact_id'] = $payload['contact_id'];
        }

        if (array_key_exists('address_id', $payload) && $payload['address_id'] !== null) {
            $attributes['address_id'] = $payload['address_id'];
        }

        if ($payload['step'] === 'payment') {
            $attributes['payment_method'] = $payload['payment_method'] ?? null;
            $attributes['coupon_id'] = $payload['coupon_id'] ?? null;
            if (array_key_exists('promo_validation_token', $payload)) {
                $attributes['promo_validation_token'] = $payload['promo_validation_token'];
                if ($payload['promo_validation_token'] !== null) {
                    $attributes['coupon_id'] = null;
                }
            }
            if (($payload['coupon_id'] ?? null) !== null) {
                $attributes['promo_validation_token'] = null;
            }
        }

        $draft = LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => $laboratoryBrand,
            ],
            $attributes,
        );

        $this->touchMonitoringCart($customer);
        $this->recordCheckoutEvents($customer, $laboratoryBrand, $payload);

        return $draft->fresh(['contact', 'address', 'coupon']);
    }

    public function clearForCustomer(Customer $customer, ?LaboratoryBrand $laboratoryBrand = null): void
    {
        $query = LaboratoryCheckoutDraft::query()->where('customer_id', $customer->id);

        if ($laboratoryBrand !== null) {
            $query->where('laboratory_brand', $laboratoryBrand);
        }

        $query->delete();
    }

    private function touchMonitoringCart(Customer $customer): void
    {
        if (! $customer->user_id) {
            return;
        }

        Cart::query()
            ->where('user_id', $customer->user_id)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->update(['updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordCheckoutEvents(Customer $customer, LaboratoryBrand $laboratoryBrand, array $payload): void
    {
        $cart = $this->activeLaboratoryCart($customer, $laboratoryBrand);
        if (! $cart) {
            return;
        }

        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::CheckoutStarted,
            "cart:{$cart->id}:checkout_started",
            ['brand' => $laboratoryBrand->value],
            source: 'laboratory_checkout',
        );

        if (array_key_exists('contact_id', $payload) && $payload['contact_id'] !== null) {
            $this->cartEventRecorder->recordOnce(
                $cart,
                CartEventType::PatientSelected,
                "cart:{$cart->id}:patient:{$payload['contact_id']}",
                [
                    'contact_id' => (int) $payload['contact_id'],
                    'brand' => $laboratoryBrand->value,
                ],
                source: 'laboratory_checkout',
            );
        }

        if (array_key_exists('address_id', $payload) && $payload['address_id'] !== null) {
            $this->cartEventRecorder->recordOnce(
                $cart,
                CartEventType::AddressSelected,
                "cart:{$cart->id}:address:{$payload['address_id']}",
                [
                    'address_id' => (int) $payload['address_id'],
                    'brand' => $laboratoryBrand->value,
                ],
                source: 'laboratory_checkout',
            );
        }
    }

    private function activeLaboratoryCart(Customer $customer, LaboratoryBrand $laboratoryBrand): ?Cart
    {
        if (! $customer->user_id) {
            return null;
        }

        return Cart::query()
            ->with('items')
            ->where('user_id', $customer->user_id)
            ->where('type', MonitoringCartType::Lab)
            ->where('status', MonitoringCartStatus::Active)
            ->get()
            ->first(function (Cart $cart) use ($laboratoryBrand) {
                $brands = collect($cart->labBrands())->pluck('value')->filter()->values();

                return $brands->count() === 1 && $brands->first() === $laboratoryBrand->value;
            });
    }
}
