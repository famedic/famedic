<?php

namespace App\Actions\PayPal;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Enums\CartEventType;
use App\Enums\LaboratoryBrand;
use App\Exceptions\MissingLaboratoryAppointmentException;
use App\Exceptions\PayPalPaymentException;
use App\Exceptions\PayPalRecoveryConfirmationPendingException;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\Carts\CartEventRecorder;
use App\Services\CouponApplicationService;
use App\Services\Monitoring\SyncMonitoringCartService;
use App\Services\PayPalService;
use App\Services\PromoCodeService;
use App\Support\PaymentAuthenticationRecoveryPayPalOrderHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreatePayPalOrderAction
{
    public function __construct(
        private CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        private PayPalService $payPalService,
        private CouponApplicationService $couponApplicationService,
        private PromoCodeService $promoCodeService,
        private SyncMonitoringCartService $syncMonitoringCartService,
        private CartEventRecorder $cartEventRecorder,
        private PaymentAuthenticationRecoveryPayPalOrderHelper $recoveryPayPalOrderHelper,
    ) {}

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
        ?array $clientContext = null,
        ?string $recoveryContextUuid = null,
    ): array {
        if (! $laboratoryBrand instanceof LaboratoryBrand) {
            $laboratoryBrand = LaboratoryBrand::from($laboratoryBrand);
        }

        $cartItems = $customer->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->with('laboratoryTest')
            ->get();

        $totals = ($this->calculateTotalsAndDiscountAction)($cartItems);
        if ($totalCents !== $totals['total']) {
            throw new UnmatchingTotalPriceException;
        }

        if ($couponId !== null && $promoValidationToken !== null) {
            throw new PayPalPaymentException('No se puede combinar cupón asignado con código promocional.');
        }

        $cartHash = $this->promoCodeService->buildLaboratoryCartHash($cartItems, $totalCents);
        $discountCents = 0;

        if ($promoValidationToken !== null) {
            $redemption = $this->promoCodeService->resolveValidatedRedemption(
                $customer->user,
                $promoValidationToken,
                $totalCents,
                $cartHash,
            );
            $discountCents = (int) $redemption->discount_cents;
        } elseif ($couponId !== null) {
            $this->couponApplicationService->validateApplication(
                $customer->user,
                $couponId,
                $totalCents
            );
            $discountCents = $this->couponApplicationService->resolveDiscountCents(
                Coupon::query()->findOrFail($couponId),
                $totalCents
            );
        }

        $amountToChargeCents = $totalCents - $discountCents;
        if ($amountToChargeCents <= 0) {
            throw new PayPalPaymentException('El saldo a favor cubre el total; no se requiere PayPal.');
        }

        $laboratoryAppointment = $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($laboratoryBrand);

        if ($customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand)) {
            if (! $laboratoryAppointment) {
                throw new MissingLaboratoryAppointmentException;
            }
        }

        $amount = round($amountToChargeCents / 100, 2);

        $recoveryBootstrap = null;
        if ($recoveryContextUuid) {
            $recoveryBootstrap = $this->recoveryPayPalOrderHelper->bootstrapForOrder(
                $customer,
                $recoveryContextUuid,
                $amountToChargeCents
            );

            if ($recoveryBootstrap['reused']) {
                return $recoveryBootstrap['reused'];
            }

            $this->recoveryPayPalOrderHelper->recordOrderRequestStarted(
                $recoveryBootstrap['context'],
                $recoveryBootstrap['attempt']
            );
        }

        $transaction = DB::transaction(function () use (
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
            $recoveryBootstrap,
        ) {
            $tempReference = 'PAYPAL-PENDING-'.Str::uuid()->toString();

            $details = array_filter([
                'customer_id' => $customer->id,
                'contact_id' => $contact?->id,
                'address_id' => $address->id,
                'laboratory_brand' => $laboratoryBrand->value,
                'laboratory_appointment_id' => $laboratoryAppointment?->id,
                'total_cents' => $totalCents,
                'cart_hash' => $cartHash,
                'coupon_id' => $couponId,
                'promo_validation_token' => $promoValidationToken,
                'coupon_amount_cents' => $discountCents,
                'promo_discount_cents' => $promoValidationToken !== null ? $discountCents : null,
                'original_total_cents' => $totalCents,
                'amount_charged_cents' => $amountToChargeCents,
            ], fn ($value) => $value !== null);

            if ($recoveryBootstrap) {
                $details = $this->recoveryPayPalOrderHelper->mergeRecoveryDetails(
                    $details,
                    $recoveryBootstrap['context'],
                    $recoveryBootstrap['attempt']
                );
            }

            return Transaction::create([
                'transaction_amount_cents' => $amountToChargeCents,
                'payment_method' => 'paypal',
                'payment_provider' => 'paypal',
                'gateway' => 'paypal',
                'reference_id' => $tempReference,
                'payment_status' => 'pending',
                'details' => $details,
            ]);
        });

        $customId = 'fp-'.$transaction->id;

        try {
            $paypal = $this->payPalService->createOrder(
                $amount,
                'MXN',
                $customId,
                'Laboratorio '.$laboratoryBrand->value
            );
        } catch (\Throwable $e) {
            if ($recoveryBootstrap) {
                $this->recoveryPayPalOrderHelper->markCreateTimeout(
                    $recoveryBootstrap['context'],
                    $transaction,
                    $recoveryBootstrap['attempt']
                );

                if (! $e instanceof PayPalPaymentException) {
                    throw new PayPalRecoveryConfirmationPendingException(
                        supportReference: $recoveryBootstrap['attempt']->support_reference,
                    );
                }
            }

            throw $e;
        }

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
            'recovery_context_uuid' => $recoveryContextUuid,
        ]);

        if ($recoveryBootstrap) {
            $this->recoveryPayPalOrderHelper->afterOrderCreated(
                $recoveryBootstrap['context'],
                $transaction->fresh(),
                $recoveryBootstrap['attempt']
            );
        }

        $this->recordPaymentStarted($customer, $laboratoryBrand, $transaction, $amountToChargeCents, $clientContext);

        return [
            'order_id' => $paypal['order_id'],
            'transaction_id' => $transaction->id,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    private function recordPaymentStarted(
        Customer $customer,
        LaboratoryBrand $laboratoryBrand,
        Transaction $transaction,
        int $amountCents,
        ?array $clientContext = null,
    ): void {
        $this->syncMonitoringCartService->syncLaboratory($customer, $clientContext);
        $cart = $this->syncMonitoringCartService->activeLaboratoryCart($customer, $laboratoryBrand);

        if (! $cart) {
            return;
        }

        $this->cartEventRecorder->recordOnce(
            $cart,
            CartEventType::PaymentStarted,
            "paypal_transaction:{$transaction->id}:payment_started",
            $this->withClientContext([
                'transaction_id' => $transaction->id,
                'gateway' => 'paypal',
                'status' => $transaction->payment_status,
                'amount_cents' => $amountCents,
            ], $clientContext),
            $transaction->created_at,
            'paypal',
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $clientContext
     * @return array<string, mixed>
     */
    private function withClientContext(array $metadata, ?array $clientContext): array
    {
        if ($clientContext === null || $clientContext === []) {
            return $metadata;
        }

        return array_merge($metadata, ['client' => $clientContext]);
    }
}
