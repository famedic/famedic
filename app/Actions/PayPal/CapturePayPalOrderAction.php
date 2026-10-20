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
     * @param  array<string, mixed>|null  $clientContext
     * @return array{purchase: ?LaboratoryPurchase, status: string, message?: string}
     */
    public function __invoke(string $paypalOrderId, Customer $customer, ?array $clientContext = null): array
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

        $guard = app(\App\Services\Laboratory\UncertainGdaPaymentGuard::class);
        try {
            return $guard->withTransactionLock($transaction, fn () => $this->capture($transaction->fresh(), $paypalOrderId, $clientContext));
        } catch (\Throwable) {
            try {
                if ($transaction->fresh()?->refunded_at !== null) {
                    return ['purchase' => null, 'status' => 'failed', 'message' => 'El pedido no pudo completarse y se procesó el reembolso.'];
                }
            } catch (\Throwable) { /* Keep the uncertain outcome if storage is unavailable. */ }
            \App\Services\Laboratory\GdaPaymentDiagnostics::record('warning', 'paypal_capture_requires_review', ['transaction_id' => $transaction->id]);
            return ['purchase' => null, 'status' => 'payment_uncertain', 'message' => (new \App\Exceptions\LaboratoryPaymentInProgressException)->getMessage()];
        }
    }

    private function capture(Transaction $transaction, string $paypalOrderId, ?array $clientContext): array
    {
        if ($existing = $transaction->laboratoryPurchases()->useWritePdo()->first()) {
            $purchase = app(\App\Services\Laboratory\UncertainGdaPaymentGuard::class)->existingPurchaseResult($existing);
            return ['purchase' => $purchase, 'status' => match ($purchase->gda_status) {
                \App\Enums\GdaOrderStatus::Pending, \App\Enums\GdaOrderStatus::Uncertain => 'gda_uncertain',
                \App\Enums\GdaOrderStatus::Failed => 'failed',
                default => 'already_processed',
            }];
        }

        if (($transaction->payment_status ?? '') === 'failed') {
            return ['purchase' => null, 'status' => 'failed', 'message' => 'El pago fue rechazado.'];
        }

        $guard = app(\App\Services\Laboratory\UncertainGdaPaymentGuard::class);
        $cart = $guard->cartForTransaction($transaction);
        if (! $cart) {
            return ['purchase' => null, 'status' => 'error', 'message' => 'No se pudo verificar el carrito de este pago.'];
        }
        if ($other = $guard->capturedUncertainPurchaseForCart($cart)) {
            return ['purchase' => $other, 'status' => 'gda_uncertain', 'message' => 'Tu pago ya fue recibido; revisa tu orden.'];
        }
        // Recover an already captured order before issuing another capture request.
        $guard->ensureNoUnresolvedCapture($cart, $transaction->id);
        try {
            $observed = $transaction->payment_status === 'captured' && is_array($transaction->raw_response)
                ? $transaction->raw_response : $this->payPalService->getOrder($paypalOrderId);
            $observedInfo = $this->payPalService->extractCaptureInfo($observed);
            if (($observedInfo['capture_id'] ?? null) !== null && in_array(strtoupper((string) ($observedInfo['status'] ?? '')), ['COMPLETED', 'CAPTURED'], true)) {
                $purchase = ($this->finalizeLaboratoryPayPalPaymentAction)($transaction, $observed, $clientContext);
                return ['purchase' => $purchase, 'status' => $purchase?->gda_status === \App\Enums\GdaOrderStatus::Uncertain ? 'gda_uncertain' : ($purchase ? 'captured' : 'error')];
            }
        } catch (\Throwable) {
            return ['purchase' => null, 'status' => isset($transaction->details['laboratory_capture_started_at']) ? 'payment_uncertain' : 'error',
                'message' => (new \App\Exceptions\LaboratoryPaymentInProgressException)->getMessage()];
        }
        if (isset($transaction->details['laboratory_capture_started_at'])) {
            return ['purchase' => null, 'status' => 'payment_uncertain', 'message' => (new \App\Exceptions\LaboratoryPaymentInProgressException)->getMessage()];
        }
        $transaction->update(['details' => [...($transaction->details ?? []), 'laboratory_capture_started_at' => now()->toIso8601String()]]);
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

        $purchase = ($this->finalizeLaboratoryPayPalPaymentAction)($transaction, $capturePayload, $clientContext);

        return [
            'purchase' => $purchase,
            'status' => $purchase?->gda_status === \App\Enums\GdaOrderStatus::Uncertain ? 'gda_uncertain' : ($purchase ? 'captured' : 'error'),
        ];
    }
}
