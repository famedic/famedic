<?php

namespace App\Actions\Payments\HeyBanco;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Actions\Laboratories\FulfillLaboratoryCartOrderAction;
use App\Actions\Transactions\RefundTransactionAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\MissingLaboratoryAppointmentException;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\Payment3dsSession;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

class FulfillLaboratoryHeyBanco3dsPaymentAction
{
    public function __construct(
        private CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        private FulfillLaboratoryCartOrderAction $fulfillLaboratoryCartOrderAction,
        private RefundTransactionAction $refundTransactionAction,
    ) {}

    public function __invoke(Payment3dsSession $session, Transaction $transaction): ?LaboratoryPurchase
    {
        $context = is_array($session->checkout_context) ? $session->checkout_context : [];

        if (($context['type'] ?? null) !== 'laboratory_checkout') {
            Log::warning('[HeyBanco3DS] Contexto de checkout no soportado para fulfillment', [
                'session_id' => $session->id,
                'context_type' => $context['type'] ?? null,
            ]);

            return null;
        }

        if ($transaction->laboratoryPurchases()->exists()) {
            return $transaction->laboratoryPurchases()->first();
        }

        try {
            return $this->runFulfillment($session, $transaction, $context);
        } catch (Throwable $e) {
            Log::error('[HeyBanco3DS] Fallo fulfillment tras pago 3DS aprobado', [
                'session_id' => $session->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            try {
                ($this->refundTransactionAction)($transaction);
            } catch (Throwable $refundError) {
                Log::error('[HeyBanco3DS] Reembolso tras fallo de fulfillment falló', [
                    'transaction_id' => $transaction->id,
                    'error' => $refundError->getMessage(),
                ]);

                $transaction->update([
                    'gateway_status' => 'refund_pending',
                ]);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function runFulfillment(
        Payment3dsSession $session,
        Transaction $transaction,
        array $context,
    ): LaboratoryPurchase {
        $customer = Customer::find($context['customer_id'] ?? $session->customer_id);

        if (! $customer) {
            throw new \RuntimeException('Cliente no encontrado para sesión 3DS.');
        }

        $brand = LaboratoryBrand::from($context['laboratory_brand']);

        $address = Address::find($context['address_id'] ?? null);
        if (! $address || $address->customer_id !== $customer->id) {
            throw new \RuntimeException('Dirección inválida para sesión 3DS.');
        }

        $cartItems = $customer->laboratoryCartItems()
            ->ofBrand($brand)
            ->with('laboratoryTest')
            ->get();

        $totals = ($this->calculateTotalsAndDiscountAction)($cartItems);

        if ((int) ($context['total_cents'] ?? 0) !== $totals['total']) {
            throw new UnmatchingTotalPriceException();
        }

        $laboratoryAppointment = isset($context['laboratory_appointment_id'])
            ? LaboratoryAppointment::where('id', $context['laboratory_appointment_id'])
                ->where('customer_id', $customer->id)
                ->first()
            : null;

        $patient = $this->resolvePatient($customer, $brand, $context, $laboratoryAppointment);

        return ($this->fulfillLaboratoryCartOrderAction)(
            $customer,
            $brand,
            $address,
            $patient,
            $transaction,
            $laboratoryAppointment,
            $cartItems,
            $brand->value,
            isset($context['coupon_id']) ? (int) $context['coupon_id'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolvePatient(
        Customer $customer,
        LaboratoryBrand $brand,
        array $context,
        ?LaboratoryAppointment $laboratoryAppointment,
    ): Contact {
        if ($customer->getHasLaboratoryCartItemRequiringAppointment($brand)) {
            if (! $laboratoryAppointment) {
                throw new MissingLaboratoryAppointmentException();
            }

            return new Contact([
                'name' => $laboratoryAppointment->patient_name,
                'paternal_lastname' => $laboratoryAppointment->patient_paternal_lastname,
                'maternal_lastname' => $laboratoryAppointment->patient_maternal_lastname,
                'birth_date' => $laboratoryAppointment->patient_birth_date,
                'gender' => $laboratoryAppointment->patient_gender,
                'phone' => str_replace(' ', '', (new PhoneNumber($laboratoryAppointment->patient_phone, $laboratoryAppointment->patient_phone_country))->formatNational()),
                'phone_country' => $laboratoryAppointment->patient_phone_country,
            ]);
        }

        $contact = Contact::find($context['contact_id'] ?? null);
        if (! $contact || $contact->customer_id !== $customer->id) {
            throw new \RuntimeException('Paciente / contacto inválido para sesión 3DS.');
        }

        return $contact;
    }
}
