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
                'payment_method_id' => $paymentMethod,
            ]);

            // 🔥 1. Buscar token real en base de datos
            $token = \App\Models\EfevooToken::where('id', $paymentMethod)
                ->where('customer_id', $customer->id)
                ->active()
                ->firstOrFail();

            Log::info('Token encontrado', [
                'token_id_db' => $token->id,
                'environment_token' => $token->environment,
                'card_token_preview' => substr($token->card_token, 0, 20)
            ]);

            // 🔥 2. Validar ambiente
            if ($token->environment !== config('efevoopay.environment')) {
                throw new \Exception('Token pertenece a otro ambiente');
            }

            // 🔥 3. Preparar datos con card_token real
            $chargeData = [
                'token_id' => $token->card_token, // 👈 AQUÍ ESTÁ LA CLAVE
                'amount' => $amountCents / 100,
                'reference' => 'LAB-' . $customer->id . '-' . time(),
            ];

            $result = $this->efevooPayService->chargeCard($chargeData);

            if (!$result['success']) {
                throw new \Exception($result['message'] ?? 'Error al procesar el pago');
            }

            // 🔥 4. Crear transacción
            return Transaction::create([
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
                'currency' => 'MXN',
                'payment_gateway' => 'efevoopay',
                'gateway_transaction_id' => $result['transaction_id'] ?? null,
                'authorization_code' => $result['authorization_code'] ?? null,
                'status' => $result['status'] ?? 'completed',
                'metadata' => [
                    'efevoo_response' => $result,
                    'efevoo_token_id' => $token->id,
                ],
            ]);

        } catch (\Exception $e) {

            Log::error('Error en ChargeEfevooPaymentMethodAction', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
            ]);

            throw $e;
        }
    }

}