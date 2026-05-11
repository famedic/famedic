<?php

namespace App\Actions\PayPal;

use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\Transaction;
use App\Services\PayPalService;
use Illuminate\Support\Facades\Log;

class CapturePayPalOrderAction
{
    public function __construct(
        private PayPalService $payPalService,
        private FinalizeLaboratoryPayPalPaymentAction $finalizeLaboratoryPayPalPaymentAction,
    ) {
    }

    /**
     * @return array{purchase: ?LaboratoryPurchase, status: string, message?: string}
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
            Log::warning('[PayPal] Capture: transacción no encontrada', ['order_id' => $paypalOrderId]);

            return ['purchase' => null, 'status' => 'not_found', 'message' => 'Orden no encontrada.'];
        }

        $details = is_array($transaction->details) ? $transaction->details : [];
        if ((int) ($details['customer_id'] ?? 0) !== $customer->id) {
            Log::warning('[PayPal] Capture: cliente no coincide', [
                'order_id' => $paypalOrderId,
                'transaction_id' => $transaction->id,
            ]);

            return ['purchase' => null, 'status' => 'forbidden', 'message' => 'La orden no pertenece al usuario.'];
        }

        if ($transaction->laboratoryPurchases()->exists()) {
            return [
                'purchase' => $transaction->laboratoryPurchases()->first(),
                'status' => 'already_processed',
            ];
        }

        if (($transaction->payment_status ?? '') === 'failed') {
            return ['purchase' => null, 'status' => 'failed', 'message' => 'El pago fue rechazado.'];
        }

        try {
            $capturePayload = $this->payPalService->captureOrder($paypalOrderId);
        } catch (\Throwable $e) {
            Log::warning('[PayPal] captureOrder error, intentando getOrder', [
                'order_id' => $paypalOrderId,
                'error' => $e->getMessage(),
            ]);
            try {
                $capturePayload = $this->payPalService->getOrder($paypalOrderId);
            } catch (\Throwable $e2) {
                Log::error('[PayPal] capture y getOrder fallaron', [
                    'order_id' => $paypalOrderId,
                    'error' => $e2->getMessage(),
                ]);

                return ['purchase' => null, 'status' => 'error', 'message' => 'No se pudo capturar el pago.'];
            }
        }

        $info = $this->payPalService->extractCaptureInfo($capturePayload);
        if (($info['capture_id'] ?? null) === null) {
            Log::error('[PayPal] Capture sin datos de captura', ['order_id' => $paypalOrderId]);

            return ['purchase' => null, 'status' => 'invalid_capture', 'message' => 'Respuesta de PayPal incompleta.'];
        }

        $purchase = ($this->finalizeLaboratoryPayPalPaymentAction)($transaction, $capturePayload);

        return [
            'purchase' => $purchase,
            'status' => $purchase ? 'captured' : 'error',
        ];
    }
}
