<?php

namespace App\Actions\EfevooPay;

use App\Models\Transaction;
use App\Services\EfevooPayFactoryService;
use Illuminate\Support\Facades\Log;

class RefundEfevooTransactionAction
{
    protected EfevooPayFactoryService $efevooPayService;

    public function __construct(EfevooPayFactoryService $efevooPayService)
    {
        $this->efevooPayService = $efevooPayService;
    }

    public function __invoke(Transaction $transaction): bool
    {
        try {
            Log::info('Iniciando reembolso EfevooPay', [
                'transaction_id' => $transaction->id,
                'gateway_transaction_id' => $transaction->gateway_transaction_id,
                'amount_cents' => $transaction->amount_cents,
            ]);

            // Verificar si la transacción fue procesada por EfevooPay
            if ($transaction->payment_gateway !== 'efevoopay') {
                Log::warning('Transacción no es de EfevooPay', [
                    'payment_gateway' => $transaction->payment_gateway,
                ]);
                return false;
            }

            // Verificar si ya tiene un reembolso
            if ($transaction->refunded_at) {
                Log::warning('Transacción ya fue reembolsada', [
                    'refunded_at' => $transaction->refunded_at,
                ]);
                return false;
            }

            // Preparar datos para reembolso
            $refundData = [
                'original_transaction_id' => $transaction->gateway_transaction_id,
                'amount' => $transaction->amount_cents / 100,
                'reason' => 'refund',
            ];

            // Realizar reembolso
            $result = $this->efevooPayService->refundTransaction($refundData);

            if (!$result['success']) {
                Log::error('Error en reembolso EfevooPay', [
                    'result' => $result,
                    'transaction_id' => $transaction->id,
                ]);
                return false;
            }

            // Actualizar transacción
            $transaction->update([
                'refunded_at' => now(),
                'status' => 'refunded',
                'metadata' => array_merge(
                    $transaction->metadata ?? [],
                    [
                        'refund_response' => $result,
                        'refund_id' => $result['refund_id'] ?? null,
                        'refunded_at' => now()->toISOString(),
                    ]
                ),
            ]);

            Log::info('Reembolso exitoso', [
                'transaction_id' => $transaction->id,
                'refund_id' => $result['refund_id'] ?? null,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Excepción en RefundEfevooTransactionAction', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }
}