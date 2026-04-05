<?php

namespace App\Actions\Laboratories;

use App\Actions\Odessa\ChargeOdessaAction;
use App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction;
use App\Actions\Transactions\RefundTransactionAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\MissingLaboratoryAppointmentException;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Propaganistas\LaravelPhone\PhoneNumber;

class OrderAction
{
    private CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction;
    private ChargeEfevooPaymentMethodAction $chargeEfevooPaymentMethodAction;
    private ChargeOdessaAction $chargeOdessaAction;
    private RefundTransactionAction $refundTransactionAction;
    private FulfillLaboratoryCartOrderAction $fulfillLaboratoryCartOrderAction;

    private Collection $laboratoryCartItems;

    public function __construct(
        CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        ChargeEfevooPaymentMethodAction $chargeEfevooPaymentMethodAction,
        ChargeOdessaAction $chargeOdessaAction,
        RefundTransactionAction $refundTransactionAction,
        FulfillLaboratoryCartOrderAction $fulfillLaboratoryCartOrderAction
    ) {
        $this->calculateTotalsAndDiscountAction = $calculateTotalsAndDiscountAction;
        $this->chargeEfevooPaymentMethodAction = $chargeEfevooPaymentMethodAction;
        $this->chargeOdessaAction = $chargeOdessaAction;
        $this->refundTransactionAction = $refundTransactionAction;
        $this->fulfillLaboratoryCartOrderAction = $fulfillLaboratoryCartOrderAction;
    }

    public function __invoke(
        Customer $customer,
        Address $address,
        ?Contact $contact,
        string $paymentMethod,
        LaboratoryBrand $laboratoryBrand,
        int $totalCents
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
            DB::beginTransaction();

            $transaction = $this->chargeAndCreateTransaction($totalCents, $paymentMethod, $customer);

            DB::commit();

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
            );
        } catch (\Throwable $th) {
            DB::rollBack();

            if ($transaction) {
                //($this->refundTransactionAction)->refund($transaction);
                ($this->refundTransactionAction)($transaction);
            }

            throw $th;
        }

        return $laboratoryPurchase;
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
}