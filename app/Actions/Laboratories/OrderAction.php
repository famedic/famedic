<?php

namespace App\Actions\Laboratories;

use App\Actions\Odessa\ChargeOdessaAction;
use App\Actions\Stripe\ChargeStripePaymentMethodAction;
use App\Actions\Transactions\RefundTransactionAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\MissingLaboratoryAppointmentException;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\Transaction;
use App\Notifications\LaboratoryPurchaseCreated;
use App\Notifications\FewDaysLeftToRequestInvoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Propaganistas\LaravelPhone\PhoneNumber;

class OrderAction
{
    private CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction;
    private ChargeStripePaymentMethodAction $chargeStripePaymentMethodAction;
    private ChargeOdessaAction $chargeOdessaAction;
    private CreateGDAQuotationAction $createGDAQuotationAction;
    private RefundTransactionAction $refundTransactionAction;

    private Collection $laboratoryCartItems;

    public function __construct(
        CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        ChargeStripePaymentMethodAction $chargeStripePaymentMethodAction,
        ChargeOdessaAction $chargeOdessaAction,
        CreateGDAQuotationAction $createGDAQuotationAction,
        RefundTransactionAction $refundTransactionAction
    ) {
        $this->calculateTotalsAndDiscountAction = $calculateTotalsAndDiscountAction;
        $this->chargeStripePaymentMethodAction = $chargeStripePaymentMethodAction;
        $this->chargeOdessaAction = $chargeOdessaAction;
        $this->createGDAQuotationAction = $createGDAQuotationAction;
        $this->refundTransactionAction = $refundTransactionAction;
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

            DB::beginTransaction();

            $laboratoryPurchase = $this->createLaboratoryPurchase($customer, $laboratoryBrand, $patient, $patientAddress);

            $laboratoryPurchase->transactions()->attach($transaction);

            if ($customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand)) {
                $laboratoryAppointment->laboratory_purchase_id = $laboratoryPurchase->id;
                $laboratoryAppointment->save();
            }

            if (app()->environment('local')) {
                $gdaQuotation = ['id' => rand(100000, 999999)];
            } else {
                $gdaQuotation = ($this->createGDAQuotationAction)(
                    $customer,
                    $patientAddress,
                    $patient,
                    request()->laboratory_brand->value,
                    $this->laboratoryCartItems,
                    $laboratoryPurchase->id
                );
            }

            $laboratoryPurchase->update([
                'gda_order_id' => $gdaQuotation['id'],
            ]);

            $this->clearCart($customer);


            DB::commit();

            $laboratoryPurchase->customer->user->notify(new LaboratoryPurchaseCreated($laboratoryPurchase));

            $this->checkAndSendInvoiceDeadlineNotification($laboratoryPurchase);
        } catch (\Throwable $th) {
            DB::rollBack();

            if ($transaction) {
                ($this->refundTransactionAction)($transaction);
            }

            throw $th;
        }

        return $laboratoryPurchase;
    }

    public function clearCart(Customer $customer)
    {
        $customer->laboratoryCartItems()->delete();
    }

    private function chargeAndCreateTransaction(int $amountCents, string $paymentMethod, Customer $customer): Transaction
    {
        if ($paymentMethod === 'odessa') {
            return ($this->chargeOdessaAction)($customer->customerable, $amountCents);
        }

        return ($this->chargeStripePaymentMethodAction)(
            $customer,
            $amountCents,
            $paymentMethod
        );
    }

    private function createLaboratoryPurchase(Customer $customer, LaboratoryBrand $laboratoryBrand, Contact $contact, Address $address): LaboratoryPurchase
    {
        $totalCents = $this->laboratoryCartItems->sum(function ($laboratoryCartItem) {
            return $laboratoryCartItem->laboratoryTest->famedic_price_cents;
        });

        $laboratoryPurchase = $customer->laboratoryPurchases()->save(
            new LaboratoryPurchase([
                'gda_order_id' => 0,
                'brand' => $laboratoryBrand->value,
                'name' => $contact->name,
                'paternal_lastname' => $contact->paternal_lastname,
                'maternal_lastname' => $contact->maternal_lastname,
                'phone' => str_replace(' ', '', (new PhoneNumber($contact->phone, $contact->phone_country))->formatNational()),
                'phone_country' => $contact->phone_country,
                'birth_date' => $contact->birth_date,
                'gender' => $contact->gender,
                'street' => $address->street,
                'number' => $address->number,
                'neighborhood' => $address->neighborhood,
                'state' => $address->state,
                'city' => $address->city,
                'zipcode' => $address->zipcode,
                'additional_references' => $address->additional_references,
                'total_cents' => $totalCents,
            ])
        );

        foreach ($this->laboratoryCartItems as $laboratoryCartItem) {
            $laboratoryPurchase->laboratoryPurchaseItems()->save(
                new LaboratoryPurchaseItem([
                    'name' => $laboratoryCartItem->laboratoryTest->name,
                    'gda_id' => $laboratoryCartItem->laboratoryTest->gda_id,
                    'indications' => $laboratoryCartItem->laboratoryTest->indications,
                    'price_cents' => $laboratoryCartItem->laboratoryTest->famedic_price_cents,
                ])
            );
        }

        return $laboratoryPurchase;
    }

    private function checkAndSendInvoiceDeadlineNotification(LaboratoryPurchase $laboratoryPurchase): void
    {
        if (!$laboratoryPurchase->customer->taxProfiles()->exists()) {
            return;
        }

        $lastDayOfPurchaseMonth = $laboratoryPurchase->created_at->endOfMonth();
        $daysLeft = now()->diffInDays($lastDayOfPurchaseMonth);

        if ($daysLeft <= 7) {
            $laboratoryPurchase->customer->user->notify(
                new FewDaysLeftToRequestInvoice($laboratoryPurchase, $daysLeft)
            );
        }
    }
}
