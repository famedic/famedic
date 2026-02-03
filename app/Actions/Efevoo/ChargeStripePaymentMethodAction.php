<?php

namespace App\Actions\EfevooPay;

use App\Models\Customer;
use App\Models\Transaction;
use App\Services\EfevooPayFactoryService;
use Illuminate\Support\Facades\Log;

class ChargeEfevooPaymentMethodAction
{
    protected EfevooPayFactoryService $efevooPayService;

    public function __construct(EfevooPayFactoryService $efevooPayService)
    {
        $this->efevooPayService = $efevooPayService;
    }

    public function __invoke(Customer $customer, int $amountCents, string $paymentMethod): Transaction
    {
        try {
            Log::info('Iniciando cargo con EfevooPay', [
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
                'payment_method' => $paymentMethod,
                'amount_mxn' => $amountCents / 100,
            ]);

            // Verificar si el paymentMethod es un token de Efevoo
            if (!str_starts_with($paymentMethod, 'tok_')) {
                throw new \Exception('Formato de token de pago inválido');
            }

            // Preparar datos para el cargo
            $chargeData = [
                'token_id' => $paymentMethod,
                'amount' => $amountCents / 100, // Convertir centavos a pesos
                'description' => 'Compra de estudios de laboratorio',
                'customer_id' => $customer->id,
            ];

            // Realizar el cargo
            $result = $this->efevooPayService->chargeCard($chargeData);

            if (!$result['success']) {
                Log::error('Error en cargo EfevooPay', [
                    'result' => $result,
                    'customer_id' => $customer->id,
                ]);
                throw new \Exception($result['message'] ?? 'Error al procesar el pago');
            }

            Log::info('Cargo exitoso con EfevooPay', [
                'transaction_id' => $result['transaction_id'] ?? null,
                'authorization_code' => $result['authorization_code'] ?? null,
                'customer_id' => $customer->id,
            ]);

            // Crear transacción en la base de datos
            $transaction = Transaction::create([
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
                'currency' => 'MXN',
                'payment_gateway' => 'efevoopay',
                'gateway_transaction_id' => $result['transaction_id'] ?? null,
                'authorization_code' => $result['authorization_code'] ?? null,
                'status' => $result['status'] ?? 'completed',
                'metadata' => [
                    'efevoo_response' => $result,
                    'token_id' => $paymentMethod,
                    'description' => 'Laboratory purchase',
                ],
            ]);

            return $transaction;

        } catch (\Exception $e) {
            Log::error('Excepción en ChargeEfevooPaymentMethodAction', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}