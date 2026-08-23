<?php

namespace App\Actions\EfevooPay;

use App\Models\Customer;
use App\Models\Transaction;
use App\Services\EfevooPayService;
use App\Support\EfevooPayLogSanitizer;
use App\Support\EfevooPayPersistenceNormalizer;
use Illuminate\Support\Facades\Log;

class ChargeEfevooPaymentMethodAction
{
    protected EfevooPayService $efevooPayService;

    public function __construct(EfevooPayService $efevooPayService)
    {
        $this->efevooPayService = $efevooPayService;
    }

    public function __invoke(Customer $customer, int $amountCents, string $paymentMethod): Transaction
    {
        try {

            Log::info('Iniciando cargo con EfevooPay', [
                'customer_id' => $customer->id,
                'token_id' => $paymentMethod,
            ]);

            // 🔥 1. Buscar token real en base de datos
            $token = \App\Models\EfevooToken::where('id', $paymentMethod)
                ->where('customer_id', $customer->id)
                ->active()
                ->firstOrFail();

            Log::info('Token encontrado', [
                'token_id_db' => $token->id,
                'environment_token' => $token->environment,
            ]);

            // 🔥 2. Validar ambiente
            if ($token->environment !== config('efevoopay.environment')) {
                throw new \Exception('Token pertenece a otro ambiente');
            }

            // 🔥 3. Preparar datos con card_token real
            $chargeData = [
                'card_token' => $token->card_token,
                'amount' => $amountCents / 100,
                'reference' => 'LAB-'.$customer->id.'-'.time(),
            ];

            $result = $this->efevooPayService->chargeCard($chargeData);

            if (! $result['success']) {
                throw new \Exception(
                    EfevooPayLogSanitizer::providerMessage($result['message'] ?? null)
                        ?? 'Error al procesar el pago'
                );
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
                    'efevoo_response' => EfevooPayPersistenceNormalizer::paymentResult($result, 'payment', [
                        'amount' => $amountCents / 100,
                        'currency' => 'MXN',
                    ]),
                    'efevoo_token_id' => $token->id,
                ],
            ]);

        } catch (\Exception $e) {

            Log::error('Error en ChargeEfevooPaymentMethodAction', [
                'customer_id' => $customer->id,
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            throw $e;
        }
    }
}
