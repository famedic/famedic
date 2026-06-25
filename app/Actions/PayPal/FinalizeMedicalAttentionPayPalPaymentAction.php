<?php

namespace App\Actions\PayPal;

use App\Actions\MedicalAttention\CreateRegularSubscriptionAction;
use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use App\Services\PayPalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeMedicalAttentionPayPalPaymentAction
{
    public function __construct(
        private PayPalService $payPalService,
        private CreateRegularSubscriptionAction $createRegularSubscriptionAction,
    ) {
    }

    /**
     * @param  array<string, mixed>  $capturePayload
     */
    public function __invoke(Transaction $transaction, array $capturePayload): ?MedicalAttentionSubscription
    {
        $info = $this->payPalService->extractCaptureInfo($capturePayload);

        if (($info['capture_id'] ?? null) === null) {
            Log::error('[PayPal][MedicalAttention] Finalize: sin capture_id', [
                'transaction_id' => $transaction->id,
            ]);

            return null;
        }

        $existingSubscription = null;

        DB::transaction(function () use ($transaction, $capturePayload, $info, &$existingSubscription) {
            $tx = Transaction::lockForUpdate()->findOrFail($transaction->id);

            if ($tx->medicalAttentionSubscriptions()->exists()) {
                $existingSubscription = $tx->medicalAttentionSubscriptions()->first();

                return;
            }

            $details = is_array($tx->details) ? $tx->details : [];

            $tx->update([
                'gateway_transaction_id' => $info['capture_id'],
                'provider_transaction_id' => $info['capture_id'],
                'payment_status' => 'captured',
                'gateway_status' => $info['status'] ?? 'COMPLETED',
                'raw_response' => $capturePayload,
                'gateway_response' => $capturePayload,
                'gateway_processed_at' => now(),
                'details' => array_merge($details, [
                    'commission_cents' => $this->extractCommissionCentsFromCapture($capturePayload),
                    'commission_fetched_at' => now()->toIso8601String(),
                    'commission_source' => 'paypal_capture',
                ]),
            ]);
        });

        if ($existingSubscription !== null) {
            return $existingSubscription;
        }

        $transaction->refresh();

        try {
            return $this->runFulfillment($transaction);
        } catch (Throwable $e) {
            Log::error('[PayPal][MedicalAttention] Fallo al crear membresía tras captura; intentando reembolso', [
                'transaction_id' => $transaction->id,
                'capture_id' => $info['capture_id'],
                'error' => $e->getMessage(),
            ]);

            try {
                $this->payPalService->refund($info['capture_id']);
            } catch (Throwable $refundError) {
                Log::error('[PayPal][MedicalAttention] Reembolso tras fallo de fulfillment falló', [
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

    private function runFulfillment(Transaction $transaction): MedicalAttentionSubscription
    {
        $details = is_array($transaction->details) ? $transaction->details : [];
        $customer = Customer::find($details['customer_id'] ?? null);

        if (!$customer) {
            throw new \RuntimeException('Cliente no encontrado para transacción PayPal de membresía médica.');
        }

        if ($customer->medicalAttentionSubscriptions()->active()->exists()) {
            throw new \RuntimeException('El cliente ya tiene una membresía médica activa.');
        }

        $expectedCents = (int) config('famedic.medical_attention_subscription_price_cents', 30000);
        if ((int) ($details['amount_cents'] ?? 0) !== $expectedCents
            || (int) $transaction->transaction_amount_cents !== $expectedCents) {
            throw new \RuntimeException('Monto de transacción PayPal no coincide con el precio de membresía.');
        }

        return DB::transaction(function () use ($customer, $transaction) {
            $tx = Transaction::lockForUpdate()->findOrFail($transaction->id);

            if ($tx->medicalAttentionSubscriptions()->exists()) {
                return $tx->medicalAttentionSubscriptions()->first();
            }

            $subscription = ($this->createRegularSubscriptionAction)($customer);

            $subscription->transactions()->attach($tx);

            $customer->update([
                'medical_attention_subscription_expires_at' => $subscription->end_date,
            ]);

            Log::info('[PayPal][MedicalAttention] Membresía creada tras captura', [
                'subscription_id' => $subscription->id,
                'transaction_id' => $tx->id,
                'customer_id' => $customer->id,
            ]);

            return $subscription;
        });
    }

    private function extractCommissionCentsFromCapture(array $capturePayload): int
    {
        $possiblePaths = [
            'purchase_units.0.payments.captures.0.seller_receivable_breakdown.paypal_fee.value',
            'purchase_units.0.payments.captures.0.seller_receivable_breakdown.paypal_fee.amount',
            'seller_receivable_breakdown.paypal_fee.value',
            'seller_receivable_breakdown.paypal_fee.amount',
            'paypal_fee.value',
            'paypal_fee.amount',
        ];

        foreach ($possiblePaths as $path) {
            $value = data_get($capturePayload, $path);
            $cents = $this->parseMoneyToCents($value);

            if ($cents !== null) {
                return $cents;
            }
        }

        return 0;
    }

    private function parseMoneyToCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 1000 ? $value : (int) round($value * 100);
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (is_string($value)) {
            $normalized = str_replace([',', '$', 'MXN', 'mxn', ' '], ['', '', '', '', ''], $value);
            if (! is_numeric($normalized)) {
                return null;
            }

            return (int) round(((float) $normalized) * 100);
        }

        if (is_array($value)) {
            foreach ([$value['value'] ?? null, $value['amount'] ?? null, $value['total'] ?? null] as $candidate) {
                $cents = $this->parseMoneyToCents($candidate);
                if ($cents !== null) {
                    return $cents;
                }
            }
        }

        return null;
    }
}
