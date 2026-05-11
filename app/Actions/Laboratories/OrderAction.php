<?php

namespace App\Actions\Laboratories;

use App\Actions\Odessa\ChargeOdessaAction;
use App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction;
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
    private ChargeEfevooPaymentMethodAction $chargeEfevooPaymentMethodAction;
    private ChargeOdessaAction $chargeOdessaAction;
    private CreateGDAQuotationAction $createGDAQuotationAction;
    private RefundTransactionAction $refundTransactionAction;
    private CouponApplicationService $couponApplicationService;
    private CreateCouponBalanceTransactionAction $createCouponBalanceTransactionAction;
    private FulfillLaboratoryCartOrderAction $fulfillLaboratoryCartOrderAction;

    private Collection $laboratoryCartItems;

    public function __construct(
        CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        ChargeEfevooPaymentMethodAction $chargeEfevooPaymentMethodAction,
        ChargeOdessaAction $chargeOdessaAction,
        CreateGDAQuotationAction $createGDAQuotationAction,
        RefundTransactionAction $refundTransactionAction,
        CouponApplicationService $couponApplicationService,
        CreateCouponBalanceTransactionAction $createCouponBalanceTransactionAction,
        FulfillLaboratoryCartOrderAction $fulfillLaboratoryCartOrderAction
    ) {
        $this->calculateTotalsAndDiscountAction = $calculateTotalsAndDiscountAction;
        $this->chargeEfevooPaymentMethodAction = $chargeEfevooPaymentMethodAction;
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
            DB::beginTransaction();

            if ($amountToChargeCents > 0) {
                $transaction = $this->chargeAndCreateTransaction($amountToChargeCents, $paymentMethod, $customer);
            } else {
                $transaction = ($this->createCouponBalanceTransactionAction)(
                    $customer,
                    (int) $couponId,
                    $discountCents
                );
            }

            DB::commit();

            $gdaBrandValue = request()->laboratory_brand->value ?? $laboratoryBrand->value;

            $laboratoryPurchase = $this->createLaboratoryPurchase($customer, $laboratoryBrand, $patient, $patientAddress);

            $laboratoryPurchase->transactions()->attach($transaction);

            if ($customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand)) {
                $laboratoryAppointment->laboratory_purchase_id = $laboratoryPurchase->id;
                $laboratoryAppointment->save();
            }

            logger('=== GDA BRAND DEBUG ===');
            logger('LaboratoryBrand Enum value: ' . $laboratoryBrand->value);
            logger('Request laboratory_brand value: ' . request()->laboratory_brand->value ?? 'NULL');
            logger('Request laboratory_brand type: ' . gettype(request()->laboratory_brand));
            logger('Request laboratory_brand: ' . json_encode(request()->laboratory_brand));

            if (app()->environment('local')) {
                $gdaQuotation = ['id' => rand(100000, 999999)];
            } else {
                $gdaQuotation = ($this->createGDAQuotationAction)(
                    $customer,
                    $patientAddress,
                    $patient,
                    request()->laboratory_brand->value ?? $laboratoryBrand->value,
                    $this->laboratoryCartItems,
                    $laboratoryPurchase->id
                );
            }

            // Actualizar la compra con los datos de GDA
            $laboratoryPurchase->update([
                'gda_order_id' => $gdaQuotation['id'],
                'gda_consecutivo' => $gdaQuotation['infogda_consecutivo'] ?? null,
                'gda_acuse' => $gdaQuotation['gda_acuse'] ?? null,
                'gda_response' => $gdaQuotation['gda_response'] ?? null,
                'gda_code_http' => $gdaQuotation['gda_code_http'] ?? null,
                'gda_mensaje' => $gdaQuotation['gda_mensaje'] ?? null,
                'gda_description' => $gdaQuotation['gda_description'] ?? null,
                'pdf_base64' => $gdaQuotation['pdf_base64'] ?? null,
            ]);

            $this->clearCart($customer);

            if ($couponId !== null) {
                $this->couponApplicationService->applyForLaboratoryPurchase(
                    $customer->user,
                    $laboratoryPurchase,
                    $couponId
                );
            }

            DB::commit();

            $laboratoryPurchase->customer->user->notify(new LaboratoryPurchaseCreated($laboratoryPurchase));

            $this->checkAndSendInvoiceDeadlineNotification($laboratoryPurchase);
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
