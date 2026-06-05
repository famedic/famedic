<?php

namespace App\Actions\Laboratories;

use App\Actions\Odessa\ChargeOdessaAction;
use App\Actions\Payments\ChargePaymentMethodAction;
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

    private Collection $laboratoryCartItems;

    public function __construct(
        CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        ChargePaymentMethodAction $chargePaymentMethodAction,
        ChargeOdessaAction $chargeOdessaAction,
        CreateGDAQuotationAction $createGDAQuotationAction,
        RefundTransactionAction $refundTransactionAction,
        CouponApplicationService $couponApplicationService,
        CreateCouponBalanceTransactionAction $createCouponBalanceTransactionAction,
        FulfillLaboratoryCartOrderAction $fulfillLaboratoryCartOrderAction
    ) {
        $this->calculateTotalsAndDiscountAction = $calculateTotalsAndDiscountAction;
        $this->chargePaymentMethodAction = $chargePaymentMethodAction;
        $this->chargeOdessaAction = $chargeOdessaAction;
        $this->createGDAQuotationAction = $createGDAQuotationAction;
        $this->refundTransactionAction = $refundTransactionAction;
        $this->couponApplicationService = $couponApplicationService;
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
                $transaction = $this->chargeAndCreateTransaction($amountToChargeCents, $paymentMethod, $customer);
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

    private function chargeAndCreateTransaction(int $amountCents, string $paymentMethod, Customer $customer): Transaction
    {
        if ($paymentMethod === 'odessa') {
            return ($this->chargeOdessaAction)($customer->customerable, $amountCents);
        }

        return ($this->chargePaymentMethodAction)(
            $customer,
            $amountCents,
            $paymentMethod
        );
    }
}
