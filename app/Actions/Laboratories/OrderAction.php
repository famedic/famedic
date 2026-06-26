<?php

namespace App\Actions\Laboratories;

use App\Actions\Odessa\ChargeOdessaAction;
use App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction;
use App\Actions\Laboratories\FulfillLaboratoryCartMembershipAction;
use App\Actions\Laboratories\ResolveLaboratoryCartTotalsAction;
use App\Actions\Transactions\CreateCouponBalanceTransactionAction;
use App\Actions\Transactions\RefundTransactionAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\MissingLaboratoryAppointmentException;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\Transaction;
use App\Services\CouponApplicationService;
use App\Services\PromoCodeService;
use App\Notifications\LaboratoryPurchaseCreated;
use App\Notifications\FewDaysLeftToRequestInvoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Propaganistas\LaravelPhone\PhoneNumber;

class OrderAction
{
    private ResolveLaboratoryCartTotalsAction $resolveLaboratoryCartTotalsAction;
    private FulfillLaboratoryCartMembershipAction $fulfillLaboratoryCartMembershipAction;
    private ChargeEfevooPaymentMethodAction $chargeEfevooPaymentMethodAction;
    private ChargeOdessaAction $chargeOdessaAction;
    private CreateGDAQuotationAction $createGDAQuotationAction;
    private RefundTransactionAction $refundTransactionAction;
    private CouponApplicationService $couponApplicationService;
    private PromoCodeService $promoCodeService;
    private CreateCouponBalanceTransactionAction $createCouponBalanceTransactionAction;
    private FulfillLaboratoryCartOrderAction $fulfillLaboratoryCartOrderAction;

    private Collection $laboratoryCartItems;

    public function __construct(
        ResolveLaboratoryCartTotalsAction $resolveLaboratoryCartTotalsAction,
        FulfillLaboratoryCartMembershipAction $fulfillLaboratoryCartMembershipAction,
        ChargeEfevooPaymentMethodAction $chargeEfevooPaymentMethodAction,
        ChargeOdessaAction $chargeOdessaAction,
        CreateGDAQuotationAction $createGDAQuotationAction,
        RefundTransactionAction $refundTransactionAction,
        CouponApplicationService $couponApplicationService,
        PromoCodeService $promoCodeService,
        CreateCouponBalanceTransactionAction $createCouponBalanceTransactionAction,
        FulfillLaboratoryCartOrderAction $fulfillLaboratoryCartOrderAction
    ) {
        $this->resolveLaboratoryCartTotalsAction = $resolveLaboratoryCartTotalsAction;
        $this->fulfillLaboratoryCartMembershipAction = $fulfillLaboratoryCartMembershipAction;
        $this->chargeEfevooPaymentMethodAction = $chargeEfevooPaymentMethodAction;
        $this->chargeOdessaAction = $chargeOdessaAction;
        $this->createGDAQuotationAction = $createGDAQuotationAction;
        $this->refundTransactionAction = $refundTransactionAction;
        $this->couponApplicationService = $couponApplicationService;
        $this->promoCodeService = $promoCodeService;
        $this->createCouponBalanceTransactionAction = $createCouponBalanceTransactionAction;
        $this->fulfillLaboratoryCartOrderAction = $fulfillLaboratoryCartOrderAction;
    }

    public function __invoke(
        Customer $customer,
        Address $address,
        ?Contact $contact,
        string $paymentMethod,
        LaboratoryBrand $laboratoryBrand,
        int $totalCents,
        ?int $couponId = null,
        ?string $promoValidationToken = null,
    ): LaboratoryPurchase {

        $this->laboratoryCartItems = $customer->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->with('laboratoryTest')
            ->get();

        $totals = ($this->resolveLaboratoryCartTotalsAction)(
            $customer,
            $laboratoryBrand,
            $this->laboratoryCartItems,
        );
        $calculatedTotalCents = (int) $totals['total'];
        $laboratoryTotalCents = (int) $totals['laboratoryTotalCents'];
        $membershipPriceCents = (int) $totals['membershipPriceCents'];
        $hasMembershipInCart = (bool) $totals['hasMembershipInCart'];

        if ($totalCents != $calculatedTotalCents) {
            throw new UnmatchingTotalPriceException();
        }

        if ($couponId !== null && $promoValidationToken !== null) {
            throw new \InvalidArgumentException('No se puede combinar cupón asignado con código promocional.');
        }

        $discountCents = 0;
        $cartHash = $this->promoCodeService->buildLaboratoryCartHash($this->laboratoryCartItems, $laboratoryTotalCents);

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
            $coupon = Coupon::query()->findOrFail($couponId);
            $discountCents = $this->couponApplicationService->resolveDiscountCents(
                $coupon,
                $laboratoryTotalCents
            );
        }

        $amountToChargeCents = max(0, $laboratoryTotalCents - $discountCents) + $membershipPriceCents;

        $laboratoryAppointment = $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($laboratoryBrand);

        $patient = null;
        $patientAddress = null;

        if (
            $customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand)
        ) {
            if (!$laboratoryAppointment) {
                throw new MissingLaboratoryAppointmentException();
            }

            $patient = new Contact([
                'name' => $laboratoryAppointment->patient_name,
                'paternal_lastname' => $laboratoryAppointment->patient_paternal_lastname,
                'maternal_lastname' => $laboratoryAppointment->patient_maternal_lastname,
                'birth_date' => $laboratoryAppointment->patient_birth_date,
                'gender' => $laboratoryAppointment->patient_gender,
                'phone' => str_replace(' ', '', (new PhoneNumber($laboratoryAppointment->patient_phone, $laboratoryAppointment->patient_phone_country))->formatNational()),
                'phone_country' => $laboratoryAppointment->patient_phone_country,
            ]);
        } else {
            $patient = $contact;
        }

        $patientAddress = $address;

        $transaction = null;

        try {
            if ($amountToChargeCents > 0) {
                $transaction = $this->chargeAndCreateTransaction($amountToChargeCents, $paymentMethod, $customer);
            } else {
                $transaction = ($this->createCouponBalanceTransactionAction)(
                    $customer,
                    $couponId,
                    $discountCents,
                    $promoValidationToken,
                );
            }

            if ($couponId !== null) {
                $this->addCouponDetailsToTransaction($transaction, (int) $couponId, $discountCents, $laboratoryTotalCents);
            } elseif ($promoValidationToken !== null) {
                $this->addPromoDetailsToTransaction($transaction, $promoValidationToken, $discountCents, $laboratoryTotalCents);
            }

            $gdaBrandValue = request()->laboratory_brand->value ?? $laboratoryBrand->value;

            $laboratoryPurchase = ($this->fulfillLaboratoryCartOrderAction)(
                $customer,
                $laboratoryBrand,
                $patientAddress,
                $patient,
                $transaction,
                $laboratoryAppointment,
                $this->laboratoryCartItems,
                $gdaBrandValue,
                $couponId,
                $promoValidationToken,
                $cartHash,
            );

            if ($hasMembershipInCart) {
                $this->addMembershipDetailsToTransaction(
                    $transaction,
                    $membershipPriceCents,
                );

                ($this->fulfillLaboratoryCartMembershipAction)(
                    $customer,
                    $laboratoryBrand,
                    $transaction,
                    hadMembershipInCart: true,
                );
            }
        } catch (\Throwable $th) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if ($transaction) {
                //($this->refundTransactionAction)->refund($transaction);
                ($this->refundTransactionAction)($transaction);
            }

            throw $th;
        }

        return $laboratoryPurchase;
    }

    private function addCouponDetailsToTransaction(
        Transaction $transaction,
        int $couponId,
        int $discountCents,
        int $originalTotalCents
    ): void {
        $details = is_array($transaction->details) ? $transaction->details : [];

        $transaction->update([
            'details' => array_merge($details, [
                'coupon_id' => $couponId,
                'coupon_amount_cents' => $discountCents,
                'original_total_cents' => $originalTotalCents,
                'amount_charged_cents' => max(0, $originalTotalCents - $discountCents),
            ]),
        ]);
    }

    private function addPromoDetailsToTransaction(
        Transaction $transaction,
        string $promoValidationToken,
        int $discountCents,
        int $originalTotalCents
    ): void {
        $details = is_array($transaction->details) ? $transaction->details : [];

        $transaction->update([
            'details' => array_merge($details, [
                'promo_validation_token' => $promoValidationToken,
                'promo_discount_cents' => $discountCents,
                'coupon_amount_cents' => $discountCents,
                'original_total_cents' => $originalTotalCents,
                'amount_charged_cents' => max(0, $originalTotalCents - $discountCents),
            ]),
        ]);
    }

    private function chargeAndCreateTransaction(int $amountCents, string $paymentMethod, Customer $customer): Transaction
    {
        if ($paymentMethod === 'odessa') {
            return ($this->chargeOdessaAction)($customer->customerable, $amountCents);
        }

        // Usar EfevooPay en lugar de Stripe
        return ($this->chargeEfevooPaymentMethodAction)(
            $customer,
            $amountCents,
            $paymentMethod
        );
    }

    private function addMembershipDetailsToTransaction(
        Transaction $transaction,
        int $membershipPriceCents,
    ): void {
        $details = is_array($transaction->details) ? $transaction->details : [];

        $transaction->update([
            'details' => array_merge($details, [
                'has_membership_in_cart' => true,
                'membership_price_cents' => $membershipPriceCents,
                'membership_fulfillment_source' => 'laboratory_checkout',
            ]),
        ]);
    }
}
