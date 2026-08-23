<?php

namespace App\Support;

use App\Enums\LaboratoryBrand;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Contact;
use App\Models\CouponUser;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Services\Monitoring\SyncMonitoringCartService;

class PaymentAuthenticationRecoveryContextDataNormalizer
{
    public const STEPS_LABORATORY = ['patient', 'address', 'payment', 'appointment', 'confirmation'];

    public const STEPS_MEDICAL = ['payment'];

    public const STEPS_PHARMACY = ['contact', 'address', 'payment'];

    public const MEDICAL_PURPOSES = ['subscription'];

    /**
     * Keys that must never be persisted, even if a caller sends them.
     *
     * @var list<string>
     */
    public const DENYLIST = [
        'card_number',
        'pan',
        'cvv',
        'cvc',
        'payment_method',
        'promo_validation_token',
        'promo_token',
        'validation_token',
        'card_token',
        'client_token',
        'return_url',
        'returnUrl',
        'token',
        'token3ds',
        'url3ds',
        'challenge',
        'checkout_payload',
        'notes',
        'medical_notes',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array{context_data: array<string, mixed>, cart_id: int|null}
     */
    public function normalize(
        Customer $customer,
        PaymentAuthenticationRecoveryContextType $type,
        array $input
    ): array {
        $input = $this->stripDenied($input);

        return match ($type) {
            PaymentAuthenticationRecoveryContextType::PaymentMethodSettings => [
                'context_data' => [],
                'cart_id' => null,
            ],
            PaymentAuthenticationRecoveryContextType::LaboratoryCheckout => $this->laboratory($customer, $input),
            PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout => $this->medical($input, 'checkout'),
            PaymentAuthenticationRecoveryContextType::MedicalAttentionModal => $this->medical($input, 'modal'),
            PaymentAuthenticationRecoveryContextType::OnlinePharmacyCheckout => $this->pharmacy($customer, $input),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function assertOwned(Customer $customer, PaymentAuthenticationRecoveryContextType $type, array $input): void
    {
        $input = $this->stripDenied($input);

        match ($type) {
            PaymentAuthenticationRecoveryContextType::LaboratoryCheckout => $this->assertLaboratoryOwnership($customer, $input),
            PaymentAuthenticationRecoveryContextType::OnlinePharmacyCheckout => $this->assertPharmacyOwnership($customer, $input),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $contextData
     */
    public function assertPersistedOwnership(Customer $customer, PaymentAuthenticationRecoveryContextType $type, array $contextData, ?int $cartId): void
    {
        $this->assertOwned($customer, $type, $contextData);

        if ($cartId !== null) {
            $this->ownedCart($customer, $cartId);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function stripDenied(array $input): array
    {
        foreach (self::DENYLIST as $key) {
            unset($input[$key]);
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{context_data: array<string, mixed>, cart_id: int|null}
     */
    private function laboratory(Customer $customer, array $input): array
    {
        $brand = $this->laboratoryBrand($input['laboratory_brand'] ?? null);

        if (! $brand) {
            throw PaymentAuthenticationRecoveryContextException::invalidOrigin('La marca de laboratorio no es valida.');
        }

        $this->assertLaboratoryOwnership($customer, array_merge($input, [
            'laboratory_brand' => $brand->value,
        ]));

        $cart = app(SyncMonitoringCartService::class)->activeLaboratoryCart($customer, $brand);

        $data = [
            'laboratory_brand' => $brand->value,
        ];

        $contactId = $this->nullableInt($input['contact_id'] ?? $input['contact'] ?? null);
        $addressId = $this->nullableInt($input['address_id'] ?? $input['address'] ?? null);
        $appointmentId = $this->nullableInt($input['appointment_id'] ?? $input['appointment'] ?? null);
        $couponId = $this->nullableInt($input['coupon_id'] ?? null);
        $step = $this->allowlistedStep($input['step'] ?? null, self::STEPS_LABORATORY);

        if ($contactId) {
            $data['contact_id'] = $contactId;
        }

        if ($addressId) {
            $data['address_id'] = $addressId;
        }

        if ($appointmentId) {
            $data['appointment_id'] = $appointmentId;
        }

        if ($couponId && $this->customerOwnsCoupon($customer, $couponId)) {
            $data['coupon_id'] = $couponId;
        } elseif ($couponId) {
            throw PaymentAuthenticationRecoveryContextException::notFound();
        }

        if ($step) {
            $data['step'] = $step;
        }

        return [
            'context_data' => $data,
            'cart_id' => $cart?->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{context_data: array<string, mixed>, cart_id: int|null}
     */
    private function medical(array $input, string $source): array
    {
        $purpose = (string) ($input['purpose'] ?? 'subscription');

        if (! in_array($purpose, self::MEDICAL_PURPOSES, true)) {
            throw PaymentAuthenticationRecoveryContextException::invalidOrigin('El proposito de membresia no es valido.');
        }

        $data = [
            'purpose' => $purpose,
            'source' => $source,
        ];

        $step = $this->allowlistedStep($input['step'] ?? null, self::STEPS_MEDICAL);

        if ($step) {
            $data['step'] = $step;
        }

        return [
            'context_data' => $data,
            'cart_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{context_data: array<string, mixed>, cart_id: int|null}
     */
    private function pharmacy(Customer $customer, array $input): array
    {
        $this->assertPharmacyOwnership($customer, $input);

        $cart = app(SyncMonitoringCartService::class)->activeCartForCustomer(
            $customer,
            \App\Enums\MonitoringCartType::Pharmacy
        );

        $data = [];

        $contactId = $this->nullableInt($input['contact_id'] ?? $input['contact'] ?? null);
        $addressId = $this->nullableInt($input['address_id'] ?? $input['address'] ?? null);
        $step = $this->allowlistedStep($input['step'] ?? null, self::STEPS_PHARMACY);

        if ($contactId) {
            $data['contact_id'] = $contactId;
        }

        if ($addressId) {
            $data['address_id'] = $addressId;
        }

        if ($step) {
            $data['step'] = $step;
        }

        return [
            'context_data' => $data,
            'cart_id' => $cart?->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertLaboratoryOwnership(Customer $customer, array $input): void
    {
        $contactId = $this->nullableInt($input['contact_id'] ?? $input['contact'] ?? null);
        $addressId = $this->nullableInt($input['address_id'] ?? $input['address'] ?? null);
        $appointmentId = $this->nullableInt($input['appointment_id'] ?? $input['appointment'] ?? null);
        $cartId = $this->nullableInt($input['cart_id'] ?? null);

        if ($contactId) {
            $this->ownedContact($customer, $contactId);
        }

        if ($addressId) {
            $this->ownedAddress($customer, $addressId);
        }

        if ($appointmentId) {
            $this->ownedAppointment($customer, $appointmentId, $input['laboratory_brand'] ?? null);
        }

        if ($cartId) {
            $this->ownedCart($customer, $cartId);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertPharmacyOwnership(Customer $customer, array $input): void
    {
        $contactId = $this->nullableInt($input['contact_id'] ?? $input['contact'] ?? null);
        $addressId = $this->nullableInt($input['address_id'] ?? $input['address'] ?? null);
        $cartId = $this->nullableInt($input['cart_id'] ?? null);

        if ($contactId) {
            $this->ownedContact($customer, $contactId);
        }

        if ($addressId) {
            $this->ownedAddress($customer, $addressId);
        }

        if ($cartId) {
            $this->ownedCart($customer, $cartId);
        }
    }

    private function ownedContact(Customer $customer, int $contactId): Contact
    {
        $contact = Contact::query()
            ->whereKey($contactId)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $contact) {
            throw PaymentAuthenticationRecoveryContextException::notFound();
        }

        return $contact;
    }

    private function ownedAddress(Customer $customer, int $addressId): Address
    {
        $address = Address::query()
            ->whereKey($addressId)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $address) {
            throw PaymentAuthenticationRecoveryContextException::notFound();
        }

        return $address;
    }

    private function ownedAppointment(Customer $customer, int $appointmentId, mixed $brand): LaboratoryAppointment
    {
        $appointment = LaboratoryAppointment::query()
            ->whereKey($appointmentId)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $appointment) {
            throw PaymentAuthenticationRecoveryContextException::notFound();
        }

        $expectedBrand = $this->laboratoryBrand($brand);

        if ($expectedBrand && $appointment->brand?->value !== $expectedBrand->value) {
            throw PaymentAuthenticationRecoveryContextException::notFound();
        }

        return $appointment;
    }

    private function ownedCart(Customer $customer, int $cartId): Cart
    {
        $cart = Cart::query()
            ->whereKey($cartId)
            ->where('user_id', $customer->user_id)
            ->first();

        if (! $cart) {
            throw PaymentAuthenticationRecoveryContextException::notFound();
        }

        return $cart;
    }

    private function customerOwnsCoupon(Customer $customer, int $couponId): bool
    {
        return CouponUser::query()
            ->where('coupon_id', $couponId)
            ->where('user_id', $customer->user_id)
            ->exists();
    }

    private function laboratoryBrand(mixed $value): ?LaboratoryBrand
    {
        if ($value instanceof LaboratoryBrand) {
            return $value;
        }

        if (is_object($value) && isset($value->value)) {
            return LaboratoryBrand::tryFrom((string) $value->value);
        }

        return is_string($value) || is_int($value)
            ? LaboratoryBrand::tryFrom((string) $value)
            : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowlistedStep(mixed $step, array $allowed): ?string
    {
        if (! is_string($step) || $step === '') {
            return null;
        }

        return in_array($step, $allowed, true) ? $step : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (! is_numeric($value)) {
            throw PaymentAuthenticationRecoveryContextException::invalidOrigin('Identificador interno invalido.');
        }

        return (int) $value;
    }
}
