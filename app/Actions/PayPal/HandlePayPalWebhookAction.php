<?php

namespace App\Actions\PayPal;

use App\Models\Transaction;
use App\Services\PayPalService;
use Illuminate\Support\Facades\Log;

class HandlePayPalWebhookAction
{
    public function __construct(
        private PayPalService $payPalService,
        private FinalizeLaboratoryPayPalPaymentAction $finalizeLaboratoryPayPalPaymentAction,
        private FinalizeMedicalAttentionPayPalPaymentAction $finalizeMedicalAttentionPayPalPaymentAction,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(array $payload): void
    {
        $eventType = (string) ($payload['event_type'] ?? '');
        $resource = $payload['resource'] ?? [];
        if (!is_array($resource)) {
            return;
        }

        Log::info('[PayPal] Webhook recibido', ['event_type' => $eventType]);

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            $orderId = data_get($resource, 'supplementary_data.related_ids.order_id');
            if (!is_string($orderId) || $orderId === '') {
                Log::warning('[PayPal] Webhook COMPLETED sin order_id');

                return;
            }

            $transaction = Transaction::query()
                ->where('payment_method', 'paypal')
                ->where(function ($q) use ($orderId) {
                    $q->where('reference_id', $orderId)
                        ->orWhere('provider_order_id', $orderId);
                })
                ->first();

            if (!$transaction) {
                Log::warning('[PayPal] Webhook: transacción no encontrada', ['order_id' => $orderId]);

                return;
            }

            if ($this->isMedicalAttentionTransaction($transaction)) {
                if ($transaction->medicalAttentionSubscriptions()->exists()) {
                    return;
                }

                ($this->finalizeMedicalAttentionPayPalPaymentAction)($transaction, $resource);

                return;
            }

            if ($transaction->laboratoryPurchases()->exists()) {
                return;
            }

            ($this->finalizeLaboratoryPayPalPaymentAction)($transaction, $resource);

            return;
        }

        if ($eventType === 'PAYMENT.CAPTURE.DENIED') {
            $orderId = data_get($resource, 'supplementary_data.related_ids.order_id');
            $transaction = null;
            if (is_string($orderId) && $orderId !== '') {
                $transaction = Transaction::query()
                    ->where('payment_method', 'paypal')
                    ->where(function ($q) use ($orderId) {
                        $q->where('reference_id', $orderId)
                            ->orWhere('provider_order_id', $orderId);
                    })
                    ->first();
            }

            if ($transaction) {
                $transaction->update([
                    'payment_status' => 'failed',
                    'gateway_status' => 'DENIED',
                    'raw_response' => $resource,
                    'gateway_response' => $resource,
                ]);
            }

            return;
        }
    }

    private function isMedicalAttentionTransaction(Transaction $transaction): bool
    {
        $details = is_array($transaction->details) ? $transaction->details : [];

        return ($details['purpose'] ?? null) === CreateMedicalAttentionPayPalOrderAction::DETAILS_PURPOSE;
    }
}
