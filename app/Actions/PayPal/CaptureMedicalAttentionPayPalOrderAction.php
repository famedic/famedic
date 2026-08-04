<?php

namespace App\Actions\PayPal;

use App\Models\Customer;
use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use App\Services\PayPalService;
use Illuminate\Support\Facades\Log;

class CaptureMedicalAttentionPayPalOrderAction
{
    public function __construct(
        private PayPalService $payPalService,
        private FinalizeMedicalAttentionPayPalPaymentAction $finalizeMedicalAttentionPayPalPaymentAction,
    ) {
    }

    /**
     * @return array{subscription: ?MedicalAttentionSubscription, status: string, message?: string}
     */
    public function __invoke(string $paypalOrderId, Customer $customer): array
    {
        $transaction = Transaction::query()
            ->where('payment_method', 'paypal')
            ->where(function ($q) use ($paypalOrderId) {
                $q->where('reference_id', $paypalOrderId)
                    ->orWhere('provider_order_id', $paypalOrderId);
            })
            ->first();

        if (!$transaction) {
            Log::warning('[PayPal][MedicalAttention] Capture: transacción no encontrada', [
                'order_id' => $paypalOrderId,
            ]);

            return ['subscription' => null, 'status' => 'not_found', 'message' => 'Orden no encontrada.'];
        }

        $details = is_array($transaction->details) ? $transaction->details : [];

        if (($details['purpose'] ?? null) !== CreateMedicalAttentionPayPalOrderAction::DETAILS_PURPOSE) {
            return ['subscription' => null, 'status' => 'not_found', 'message' => 'Orden no encontrada.'];
        }

        if ((int) ($details['customer_id'] ?? 0) !== $customer->id) {
            Log::warning('[PayPal][MedicalAttention] Capture: cliente no coincide', [
                'order_id' => $paypalOrderId,
                'transaction_id' => $transaction->id,
            ]);

            return ['subscription' => null, 'status' => 'forbidden', 'message' => 'La orden no pertenece al usuario.'];
        }

        if ($transaction->medicalAttentionSubscriptions()->exists()) {
            return [
                'subscription' => $transaction->medicalAttentionSubscriptions()->first(),
                'status' => 'already_processed',
            ];
        }

        if (($transaction->payment_status ?? '') === 'failed') {
            return ['subscription' => null, 'status' => 'failed', 'message' => 'El pago fue rechazado.'];
        }

        try {
            $capturePayload = $this->payPalService->captureOrder($paypalOrderId);
        } catch (\Throwable $e) {
            Log::warning('[PayPal][MedicalAttention] captureOrder error, intentando getOrder', [
                'order_id' => $paypalOrderId,
                'error' => $e->getMessage(),
            ]);

            try {
                $capturePayload = $this->payPalService->getOrder($paypalOrderId);
            } catch (\Throwable $e2) {
                Log::error('[PayPal][MedicalAttention] capture y getOrder fallaron', [
                    'order_id' => $paypalOrderId,
                    'error' => $e2->getMessage(),
                ]);

                return ['subscription' => null, 'status' => 'error', 'message' => 'No se pudo capturar el pago.'];
            }
        }

        $info = $this->payPalService->extractCaptureInfo($capturePayload);
        if (($info['capture_id'] ?? null) === null) {
            Log::error('[PayPal][MedicalAttention] Capture sin datos de captura', [
                'order_id' => $paypalOrderId,
            ]);

            return ['subscription' => null, 'status' => 'invalid_capture', 'message' => 'Respuesta de PayPal incompleta.'];
        }

        try {
            $subscription = ($this->finalizeMedicalAttentionPayPalPaymentAction)($transaction, $capturePayload);
        } catch (\Throwable $e) {
            Log::error('[PayPal][MedicalAttention] Finalize falló', [
                'order_id' => $paypalOrderId,
                'error' => $e->getMessage(),
            ]);

            return ['subscription' => null, 'status' => 'error', 'message' => 'No se pudo activar la membresía.'];
        }

        return [
            'subscription' => $subscription,
            'status' => $subscription ? 'captured' : 'error',
        ];
    }
}
