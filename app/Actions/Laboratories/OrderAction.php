<?php

namespace App\Actions\Laboratories;

use App\Actions\Odessa\ChargeOdessaAction;
use App\Actions\Payments\ChargePaymentMethodAction;
use App\Actions\Payments\HeyBanco\InitiateHeyBanco3dsLaboratoryCheckoutAction;
use App\Support\PaymentMethodIdentifier;
use App\Support\PaymentMethodResolver;
use Illuminate\Support\Facades\Log;
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
use App\Notifications\LaboratoryPurchaseCreated;
use App\Notifications\FewDaysLeftToRequestInvoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Propaganistas\LaravelPhone\PhoneNumber;

class OrderAction
{
    private CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction;
    private ChargePaymentMethodAction $chargePaymentMethodAction;
    private ChargeOdessaAction $chargeOdessaAction;
    private CreateGDAQuotationAction $createGDAQuotationAction;
    private RefundTransactionAction $refundTransactionAction;
    private CouponApplicationService $couponApplicationService;
    private CreateCouponBalanceTransactionAction $createCouponBalanceTransactionAction;
    private FulfillLaboratoryCartOrderAction $fulfillLaboratoryCartOrderAction;
    private InitiateHeyBanco3dsLaboratoryCheckoutAction $initiateHeyBanco3dsLaboratoryCheckoutAction;

    private Collection $laboratoryCartItems;

    public function __construct(
        CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        ChargePaymentMethodAction $chargePaymentMethodAction,
        ChargeOdessaAction $chargeOdessaAction,
        CreateGDAQuotationAction $createGDAQuotationAction,
        RefundTransactionAction $refundTransactionAction,
        CouponApplicationService $couponApplicationService,
        CreateCouponBalanceTransactionAction $createCouponBalanceTransactionAction,
        FulfillLaboratoryCartOrderAction $fulfillLaboratoryCartOrderAction,
        InitiateHeyBanco3dsLaboratoryCheckoutAction $initiateHeyBanco3dsLaboratoryCheckoutAction,
    ) {
        $this->calculateTotalsAndDiscountAction = $calculateTotalsAndDiscountAction;
        $this->chargePaymentMethodAction = $chargePaymentMethodAction;
        $this->chargeOdessaAction = $chargeOdessaAction;
        $this->createGDAQuotationAction = $createGDAQuotationAction;
        $this->refundTransactionAction = $refundTransactionAction;
        $this->couponApplicationService = $couponApplicationService;
        $this->createCouponBalanceTransactionAction = $createCouponBalanceTransactionAction;
        $this->fulfillLaboratoryCartOrderAction = $fulfillLaboratoryCartOrderAction;
        $this->initiateHeyBanco3dsLaboratoryCheckoutAction = $initiateHeyBanco3dsLaboratoryCheckoutAction;
    }

    public function __invoke(
        Customer $customer,
        Address $address,
        ?Contact $contact,
        string $paymentMethod,
        LaboratoryBrand $laboratoryBrand,
        int $totalCents,
        ?int $couponId = null,
    ): LaboratoryPurchase {

        $this->laboratoryCartItems = $customer->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->with('laboratoryTest')
            ->get();

        $totals = ($this->calculateTotalsAndDiscountAction)($this->laboratoryCartItems);
        $calculatedTotalCents = $totals['total'];

        if ($totalCents != $calculatedTotalCents) {
            throw new UnmatchingTotalPriceException();
        }

        $discountCents = 0;
        if ($couponId !== null) {
            $this->couponApplicationService->validateApplication(
                $customer->user,
                $couponId,
                $calculatedTotalCents
            );
            $discountCents = (int) Coupon::query()->findOrFail($couponId)->remaining_cents;
        }

        $amountToChargeCents = $calculatedTotalCents - $discountCents;

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
                $transaction = $this->chargeAndCreateTransaction(
                    amountCents: $amountToChargeCents,
                    paymentMethod: $paymentMethod,
                    customer: $customer,
                    address: $address,
                    contact: $patient,
                    laboratoryBrand: $laboratoryBrand,
                    totalCents: $calculatedTotalCents,
                    couponId: $couponId,
                    discountCents: $discountCents,
                    laboratoryAppointment: $laboratoryAppointment,
                );
            } else {
                $transaction = ($this->createCouponBalanceTransactionAction)(
                    $customer,
                    (int) $couponId,
                    $discountCents
                );
            }

            if ($couponId !== null) {
                $this->addCouponDetailsToTransaction($transaction, (int) $couponId, $discountCents, $calculatedTotalCents);
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
            );
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

    private function chargeAndCreateTransaction(
        int $amountCents,
        string $paymentMethod,
        Customer $customer,
        Address $address,
        ?Contact $contact,
        LaboratoryBrand $laboratoryBrand,
        int $totalCents,
        ?int $couponId,
        int $discountCents,
        $laboratoryAppointment = null,
    ): Transaction {
        $paymentMethodInput = $paymentMethod;
        $paymentMethod = PaymentMethodResolver::normalizeForCustomer($customer, $paymentMethod);
        PaymentMethodResolver::logSelection($paymentMethodInput, $amountCents);

        if ($paymentMethod === 'odessa') {
            return ($this->chargeOdessaAction)($customer->customerable, $amountCents);
        }

        if ($this->shouldUseHeyBanco3ds($paymentMethod, $amountCents, $paymentMethodInput)) {
            ($this->initiateHeyBanco3dsLaboratoryCheckoutAction)(
                customer: $customer,
                address: $address,
                contact: $contact,
                paymentMethodId: $paymentMethod,
                laboratoryBrand: $laboratoryBrand,
                amountCents: $amountCents,
                totalCents: $totalCents,
                couponId: $couponId,
                discountCents: $discountCents,
                laboratoryAppointment: $laboratoryAppointment,
            );
        }

        return ($this->chargePaymentMethodAction)(
            $customer,
            $amountCents,
            $paymentMethod
        );
    }

    private function shouldUseHeyBanco3ds(
        string $paymentMethod,
        int $amountCents,
        ?string $paymentMethodInput = null,
    ): bool {
        $enabled = (bool) config('heybanco.3ds_enabled', false);
        $isHeyBancoMethod = PaymentMethodIdentifier::isHeyBanco($paymentMethod);

        $reason = match (true) {
            $amountCents <= 0 => 'amount_not_positive',
            ! $enabled => '3ds_disabled_in_config',
            ! $isHeyBancoMethod => 'payment_method_not_hey_banco_prefix',
            default => 'will_use_3ds',
        };

        Log::info('[HeyBanco3DS] shouldUseHeyBanco3ds decision', [
            'enabled' => $enabled,
            'payment_method_input' => $paymentMethodInput ?? $paymentMethod,
            'payment_method_normalized' => $paymentMethod,
            'amount_cents' => $amountCents,
            'is_hey_banco_method' => $isHeyBancoMethod,
            'reason' => $reason,
            'will_use_3ds' => $enabled && $isHeyBancoMethod && $amountCents > 0,
        ]);

        return $enabled && $isHeyBancoMethod && $amountCents > 0;
    }
}
