<?php

namespace App\Actions\PayPal;

use App\Actions\Laboratories\CalculateTotalsAndDiscountAction;
use App\Actions\Laboratories\FulfillLaboratoryCartOrderAction;
use App\Enums\LaboratoryBrand;
use App\Exceptions\MissingLaboratoryAppointmentException;
use App\Exceptions\UnmatchingTotalPriceException;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryPurchase;
use App\Models\Transaction;
use App\Services\PayPalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

class FinalizeLaboratoryPayPalPaymentAction
{
    public function __construct(
        private PayPalService $payPalService,
        private CalculateTotalsAndDiscountAction $calculateTotalsAndDiscountAction,
        private FulfillLaboratoryCartOrderAction $fulfillLaboratoryCartOrderAction,
    ) {
    }

    /**
     * Actualiza la transacción con datos de captura y genera el pedido de laboratorio (idempotente).
     *
     * @param  array<string, mixed>  $capturePayload  Respuesta de capture API o recurso de webhook
     */
    public function __invoke(Transaction $transaction, array $capturePayload): ?LaboratoryPurchase
    {
        $info = $this->payPalService->extractCaptureInfo($capturePayload);

        if (($info['capture_id'] ?? null) === null) {
            Log::error('[PayPal] Finalize: sin capture_id', ['transaction_id' => $transaction->id]);

            return null;
        }

        $existingPurchase = null;

        DB::transaction(function () use ($transaction, $capturePayload, $info, &$existingPurchase) {
            $tx = Transaction::lockForUpdate()->findOrFail($transaction->id);

            if ($tx->laboratoryPurchases()->exists()) {
                $existingPurchase = $tx->laboratoryPurchases()->first();

                return;
            }

            $tx->update([
                'gateway_transaction_id' => $info['capture_id'],
                'provider_transaction_id' => $info['capture_id'],
                'payment_status' => 'captured',
                'gateway_status' => $info['status'] ?? 'COMPLETED',
                'raw_response' => $capturePayload,
                'gateway_response' => $capturePayload,
                'gateway_processed_at' => now(),
            ]);
        });

        if ($existingPurchase !== null) {
            return $existingPurchase;
        }

        $transaction->refresh();

        try {
            return $this->runFulfillment($transaction);
        } catch (Throwable $e) {
            Log::error('[PayPal] Fallo al generar pedido tras captura; intentando reembolso', [
                'transaction_id' => $transaction->id,
                'capture_id' => $info['capture_id'],
                'error' => $e->getMessage(),
            ]);

            try {
                $this->payPalService->refund($info['capture_id']);
            } catch (Throwable $refundError) {
                Log::error('[PayPal] Reembolso tras fallo de fulfillment falló', [
                    'capture_id' => $info['capture_id'],
                    'error' => $refundError->getMessage(),
                ]);
            }

            $transaction->update([
                'payment_status' => 'failed',
                'gateway_status' => 'FULFILLMENT_FAILED',
            ]);

            throw $e;
        }
    }

    private function runFulfillment(Transaction $transaction): LaboratoryPurchase
    {
        $details = is_array($transaction->details) ? $transaction->details : [];
        $customer = Customer::find($details['customer_id'] ?? null);
        if (!$customer) {
            throw new \RuntimeException('Cliente no encontrado para transacción PayPal.');
        }

        $brand = LaboratoryBrand::from($details['laboratory_brand']);

        $address = Address::find($details['address_id'] ?? null);
        if (!$address || $address->customer_id !== $customer->id) {
            throw new \RuntimeException('Dirección inválida para transacción PayPal.');
        }

        $cartItems = $customer->laboratoryCartItems()
            ->ofBrand($brand)
            ->with('laboratoryTest')
            ->get();

        $totals = ($this->calculateTotalsAndDiscountAction)($cartItems);
        if ((int) ($details['total_cents'] ?? 0) !== $totals['total']) {
            throw new UnmatchingTotalPriceException();
        }

        $laboratoryAppointment = isset($details['laboratory_appointment_id'])
            ? LaboratoryAppointment::where('id', $details['laboratory_appointment_id'])
                ->where('customer_id', $customer->id)
                ->first()
            : null;

        $patient = $this->resolvePatient(
            $customer,
            $brand,
            $details,
            $laboratoryAppointment
        );

        $gdaBrandValue = $brand->value;

        return ($this->fulfillLaboratoryCartOrderAction)(
            $customer,
            $brand,
            $address,
            $patient,
            $transaction,
            $laboratoryAppointment,
            $cartItems,
            $gdaBrandValue,
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function resolvePatient(
        Customer $customer,
        LaboratoryBrand $brand,
        array $details,
        ?LaboratoryAppointment $laboratoryAppointment,
    ): Contact {
        if ($customer->getHasLaboratoryCartItemRequiringAppointment($brand)) {
            if (!$laboratoryAppointment) {
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

        $contact = Contact::find($details['contact_id'] ?? null);
        if (!$contact || $contact->customer_id !== $customer->id) {
            throw new \RuntimeException('Paciente / contacto inválido para transacción PayPal.');
        }

        return $contact;
    }
}
