<?php

namespace App\Actions\PayPal;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\MissingLaboratoryAppointmentException;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Transaction;
use App\Services\PayPalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreatePayPalOrderAction
{
    public function __construct(
        private CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        private PayPalService $payPalService,
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
    ): array {
        if (!$laboratoryBrand instanceof LaboratoryBrand) {
            $laboratoryBrand = LaboratoryBrand::from($laboratoryBrand);
        }

        $cartItems = $customer->laboratoryCartItems()
            ->ofBrand($laboratoryBrand)
            ->with('laboratoryTest')
            ->get();

        $totals = ($this->calculateTotalsAndDiscountAction)($cartItems);
        if ($totalCents !== $totals['total']) {
            throw new UnmatchingTotalPriceException();
        }

        $laboratoryAppointment = $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($laboratoryBrand);

        if ($customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand)) {
            if (!$laboratoryAppointment) {
                throw new MissingLaboratoryAppointmentException();
            }
        }

        $amount = round($totalCents / 100, 2);

        return DB::transaction(function () use (
            $customer,
            $address,
            $contact,
            $laboratoryBrand,
            $laboratoryAppointment,
            $totalCents,
            $amount,
        ) {
            $tempReference = 'PAYPAL-PENDING-' . Str::uuid()->toString();

            $transaction = Transaction::create([
                'transaction_amount_cents' => $totalCents,
                'payment_method' => 'paypal',
                'payment_provider' => 'paypal',
                'gateway' => 'paypal',
                'reference_id' => $tempReference,
                'payment_status' => 'pending',
                'details' => [
                    'customer_id' => $customer->id,
                    'contact_id' => $contact?->id,
                    'address_id' => $address->id,
                    'laboratory_brand' => $laboratoryBrand->value,
                    'laboratory_appointment_id' => $laboratoryAppointment?->id,
                    'total_cents' => $totalCents,
                ],
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
