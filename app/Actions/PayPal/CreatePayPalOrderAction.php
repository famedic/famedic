<?php

namespace App\Actions\PayPal;

use App\Actions\Laboratories\ResolveLaboratoryCartTotalsAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\MissingLaboratoryAppointmentException;
use App\Exceptions\PayPalPaymentException;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\CouponApplicationService;
use App\Services\PromoCodeService;
use App\Services\PayPalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreatePayPalOrderAction
{
    public function __construct(
        private ResolveLaboratoryCartTotalsAction $resolveLaboratoryCartTotalsAction,
        private PayPalService $payPalService,
        private CouponApplicationService $couponApplicationService,
        private PromoCodeService $promoCodeService,
    ) {
    }

    /**
     * @return array{order_id: string, transaction_id: int}
     */
    public function __invoke(
        Customer $customer,
        Address $address,
        ?Contact $contact,
        LaboratoryBrand|string $laboratoryBrand,
        int $totalCents,
        ?int $couponId = null,
        ?string $promoValidationToken = null,
    ): array {
        if (!$laboratoryBrand instanceof LaboratoryBrand) {
            $laboratoryBrand = LaboratoryBrand::from($laboratoryBrand);
        }

        $cartItems = $customer->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->with('laboratoryTest')
            ->get();

        $totals = ($this->resolveLaboratoryCartTotalsAction)($customer, $laboratoryBrand, $cartItems);
        $checkoutTotalCents = (int) $totals['total'];
        $laboratoryTotalCents = (int) $totals['laboratoryTotalCents'];

        if ($totalCents !== $checkoutTotalCents) {
            throw new UnmatchingTotalPriceException();
        }

        if ($couponId !== null && $promoValidationToken !== null) {
            throw new PayPalPaymentException('No se puede combinar cupón asignado con código promocional.');
        }

        $cartHash = $this->promoCodeService->buildLaboratoryCartHash($cartItems, $laboratoryTotalCents);
        $discountCents = 0;

        if ($promoValidationToken !== null) {
            $redemption = $this->promoCodeService->resolveValidatedRedemption(
                $customer->user,
                $promoValidationToken,
                $laboratoryTotalCents,
                $cartHash,
            );
            $discountCents = (int) $redemption->discount_cents;
        } elseif ($couponId !== null) {
            $this->couponApplicationService->validateApplication(
                $customer->user,
                $couponId,
                $laboratoryTotalCents
            );
            $discountCents = $this->couponApplicationService->resolveDiscountCents(
                Coupon::query()->findOrFail($couponId),
                $laboratoryTotalCents
            );
        }

        $amountToChargeCents = max(0, $laboratoryTotalCents - $discountCents) + (int) $totals['membershipPriceCents'];
        if ($amountToChargeCents <= 0) {
            throw new PayPalPaymentException('El saldo a favor cubre el total; no se requiere PayPal.');
        }

        $laboratoryAppointment = $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($laboratoryBrand);

        if ($customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand)) {
            if (!$laboratoryAppointment) {
                throw new MissingLaboratoryAppointmentException();
            }
        }

        $amount = round($amountToChargeCents / 100, 2);

        return DB::transaction(function () use (
            $customer,
            $address,
            $contact,
            $laboratoryBrand,
            $laboratoryAppointment,
            $totalCents,
            $couponId,
            $promoValidationToken,
            $cartHash,
            $discountCents,
            $amountToChargeCents,
            $amount,
            $checkoutTotalCents,
            $laboratoryTotalCents,
            $totals,
        ) {
            $tempReference = 'PAYPAL-PENDING-' . Str::uuid()->toString();

            $transaction = Transaction::create([
                'transaction_amount_cents' => $amountToChargeCents,
                'payment_method' => 'paypal',
                'payment_provider' => 'paypal',
                'gateway' => 'paypal',
                'reference_id' => $tempReference,
                'payment_status' => 'pending',
                'details' => array_filter([
                    'customer_id' => $customer->id,
                    'contact_id' => $contact?->id,
                    'address_id' => $address->id,
                    'laboratory_brand' => $laboratoryBrand->value,
                    'laboratory_appointment_id' => $laboratoryAppointment?->id,
                    'total_cents' => $checkoutTotalCents,
                    'laboratory_total_cents' => $laboratoryTotalCents,
                    'membership_price_cents' => (int) $totals['membershipPriceCents'],
                    'has_membership_in_cart' => (bool) $totals['hasMembershipInCart'],
                    'cart_hash' => $cartHash,
                    'coupon_id' => $couponId,
                    'promo_validation_token' => $promoValidationToken,
                    'coupon_amount_cents' => $discountCents,
                    'promo_discount_cents' => $promoValidationToken !== null ? $discountCents : null,
                    'original_total_cents' => $checkoutTotalCents,
                    'amount_charged_cents' => $amountToChargeCents,
                ], fn ($value) => $value !== null),
            ]);

            $customId = 'fp-' . $transaction->id;

            $paypal = $this->payPalService->createOrder(
                $amount,
                'MXN',
                $customId,
                'Laboratorio ' . $laboratoryBrand->value
            );

            $transaction->update([
                'reference_id' => $paypal['order_id'],
                'provider_order_id' => $paypal['order_id'],
                'raw_response' => $paypal['raw'],
                'gateway_response' => $paypal['raw'],
            ]);

            Log::info('[PayPal] Orden creada', [
                'transaction_id' => $transaction->id,
                'paypal_order_id' => $paypal['order_id'],
                'customer_id' => $customer->id,
            ]);

            return [
                'order_id' => $paypal['order_id'],
                'transaction_id' => $transaction->id,
            ];
        });
    }
}
