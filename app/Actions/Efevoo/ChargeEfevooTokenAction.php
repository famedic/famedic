<?php
namespace App\Actions\Efevoo;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Services\EfevooPayFactoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChargeEfevooTokenAction
{
    protected $efevooPayService;

    public function __construct(EfevooPayFactoryService $efevooPayService)
    {
        $this->efevooPayService = $efevooPayService;
    }

    public function __invoke(Customer $customer, int $amountCents, string $efevooTokenId): Transaction
    {
        Log::info('Iniciando cargo con EfevooPay', [
            'customer_id' => $customer->id,
            'amount_cents' => $amountCents,
            'efevoo_token_id' => $efevooTokenId,
        ]);

        return DB::transaction(function () use ($customer, $amountCents, $efevooTokenId) {
            // 1. Obtener el token de Efevoo
            $efevooToken = EfevooToken::where('id', $efevooTokenId)
                ->where('customer_id', $customer->id)
                ->active()
                ->firstOrFail();

            // 2. Preparar datos para el pago
            $amountDecimal = $amountCents / 100;
            
            $paymentData = [
                'amount' => $amountDecimal,
                'currency' => 'MXN',
                'description' => 'Compra Famedic',
                'reference' => 'FMD-' . time() . '-' . $customer->id,
                'metadata' => [
                    'customer_id' => $customer->id,
                    'user_email' => $customer->user->email,
                    'source' => 'laboratory_checkout',
                ],
            ];

            // 3. Procesar el pago con EfevooPay
            $paymentResult = $this->efevooPayService->processPayment(
                $paymentData,
                $customer->id,
                $efevooToken->id
            );

            Log::info('Resultado de pago EfevooPay', [
                'success' => $paymentResult['success'],
                'message' => $paymentResult['message'],
                'transaction_id' => $paymentResult['transaction_id'] ?? null,
                'authorization_code' => $paymentResult['authorization_code'] ?? null,
            ]);

            // 4. Si el pago falla, lanzar excepción
            if (!$paymentResult['success']) {
                Log::error('Pago EfevooPay fallido', [
                    'error' => $paymentResult['message'],
                    'code' => $paymentResult['code'] ?? null,
                ]);

                throw new \Exception('Pago declinado: ' . ($paymentResult['message'] ?? 'Error de procesamiento'));
            }

            // 5. Crear registro en EfevooTransaction
            $efevooTransaction = $this->createEfevooTransaction(
                $efevooToken,
                $paymentResult,
                $amountDecimal
            );

            // 6. Crear registro en Transaction (para mantener consistencia con reportes)
            $transaction = $this->createTransactionRecord(
                $customer,
                $amountCents,
                $efevooToken,
                $efevooTransaction,
                $paymentResult
            );

            Log::info('Pago procesado exitosamente', [
                'transaction_id' => $transaction->id,
                'efevoo_transaction_id' => $efevooTransaction->id,
                'amount' => $amountCents,
            ]);

            return $transaction;
        });
    }

    /**
     * Crear registro en EfevooTransaction
     */
    protected function createEfevooTransaction(
        EfevooToken $efevooToken,
        array $paymentResult,
        float $amount
    ): EfevooTransaction {
        return EfevooTransaction::create([
            'efevoo_token_id' => $efevooToken->id,
            'reference' => $paymentResult['reference'] ?? ('EVE-' . time()),
            'amount' => $amount,
            'currency' => 'MXN',
            'status' => EfevooTransaction::STATUS_APPROVED,
            'response_code' => $paymentResult['code'] ?? '00',
            'response_message' => $paymentResult['message'] ?? 'Aprobado',
            'transaction_type' => EfevooTransaction::TYPE_PAYMENT,
            'metadata' => [
                'authorization_code' => $paymentResult['authorization_code'] ?? null,
                'payment_result' => $paymentResult,
                'customer_id' => $efevooToken->customer_id,
            ],
            'request_data' => [
                'amount' => $amount,
                'currency' => 'MXN',
                'description' => 'Compra Famedic',
            ],
            'response_data' => $paymentResult['data'] ?? $paymentResult,
            'cav' => $paymentResult['authorization_code'] ?? null,
            'processed_at' => now(),
        ]);
    }

    /**
     * Crear registro en Transaction (para mantener compatibilidad)
     */
    protected function createTransactionRecord(
        Customer $customer,
        int $amountCents,
        EfevooToken $efevooToken,
        EfevooTransaction $efevooTransaction,
        array $paymentResult
    ): Transaction {
        return Transaction::create([
            'transaction_amount_cents' => $amountCents,
            'payment_method' => 'efevoopay',
            'reference_id' => $efevooTransaction->reference,
            'status' => 'completed',
            'details' => [
                'efevoo_transaction_id' => $efevooTransaction->id,
                'efevoo_token_id' => $efevooToken->id,
                'authorization_code' => $paymentResult['authorization_code'] ?? null,
                'card_last_four' => $efevooToken->card_last_four,
                'card_brand' => $efevooToken->card_brand,
                'card_expiration' => $efevooToken->card_expiration,
                'card_holder' => $efevooToken->card_holder,
                'customer_name' => $customer->user->name,
                'customer_email' => $customer->user->email,
                'payment_result' => $paymentResult,
                'commission_cents' => $this->calculateCommission($amountCents),
                'commission_fetched_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Calcular comisión (ajusta según tu modelo de negocio)
     */
    protected function calculateCommission(int $amountCents): int
    {
        // Ejemplo: 3% de comisión
        return (int) ($amountCents * 0.03);
    }
}