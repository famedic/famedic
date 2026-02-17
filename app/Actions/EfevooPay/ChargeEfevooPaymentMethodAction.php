<?php

namespace App\Actions\EfevooPay;

use App\Models\Customer;
use App\Models\Transaction;
use App\Services\EfevooPayService;
use Illuminate\Support\Facades\Log;
use App\Exceptions\EfevooPaymentException;

class ChargeEfevooPaymentMethodAction
{
    protected EfevooPayService $efevooPayService;

    public function __construct(EfevooPayService $efevooPayService)
    {
        $this->efevooPayService = $efevooPayService;
    }

    public function __invoke(Customer $customer, int $amountCents, string $paymentMethod): Transaction
    {
        $chargeData = [];
        $token = null;
        $result = null;

        try {

            Log::info('ChargeEfevooPaymentMethodAction - Iniciando', [
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
                'payment_method_input' => $paymentMethod,
            ]);

            if (empty($paymentMethod)) {
                throw new EfevooPaymentException('Token de pago inválido o vacío');
            }

            $tokenId = (string) $paymentMethod;

            $token = $customer->efevooTokens()
                ->active()
                ->where('id', $tokenId)
                ->first();

            if (!$token) {
                throw new EfevooPaymentException(
                    'El método de pago seleccionado no está disponible o ha expirado.'
                );
            }

            if ($token->isExpired()) {
                throw new EfevooPaymentException(
                    'El método de pago ha expirado. Por favor selecciona otro.'
                );
            }

            $cardToken = $token->card_token;

            if (empty($cardToken)) {
                throw new EfevooPaymentException(
                    'El token de tarjeta no está disponible. La tarjeta necesita ser tokenizada nuevamente.'
                );
            }

            $reference = 'LAB-' . $customer->id . '-' . time() . '-' . rand(1000, 9999);

            $chargeData = [
                'token_id' => $cardToken,
                'amount' => $amountCents / 100,
                'reference' => $reference,
            ];

            $result = $this->efevooPayService->chargeCard($chargeData);

            if (!$result['success']) {
                throw new EfevooPaymentException(
                    $result['message'] ?? 'Error al procesar el pago',
                    $result['code'] ?? null,
                    $result
                );
            }

            $gatewayTransactionId =
                $result['transaction_id']
                ?? $result['efevoo_transaction_id']
                ?? 'EFV-' . time();

        } catch (EfevooPaymentException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Excepción en ChargeEfevooPaymentMethodAction', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
            ]);

            throw new EfevooPaymentException(
                'Error al procesar el pago con EfevooPay: ' . $e->getMessage()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | CREAR TRANSACCIÓN EN BD
        |--------------------------------------------------------------------------
        */
        try {

            $transaction = Transaction::create([
                'transaction_amount_cents' => $amountCents,
                'payment_method' => 'efevoopay',
                'reference_id' => $reference,
                'gateway' => 'efevoopay',
                'gateway_transaction_id' => $gatewayTransactionId,
                'gateway_status' => 'completed',
                'gateway_response' => $result, // 👈 SIN json_encode
                'gateway_token' => 'efv-token-ref:' . substr(md5($cardToken), 0, 20),
                'gateway_processed_at' => now(),

                // 👇 TODO lo contextual va dentro de details
                'details' => [
                    'description' => 'Compra de estudios de laboratorio - Famedic',

                    'customer_info' => [
                        'customer_id' => $customer->id,
                        'user_id' => $customer->user_id ?? null,
                    ],

                    'token_info' => [
                        'token_id' => $token->id,
                        'card_brand' => $token->card_brand,
                        'card_last_four' => $token->card_last_four,
                        'alias' => $token->alias,
                        'environment' => $token->environment,
                        'expires_at' => $token->expires_at?->toISOString(),
                    ],

                    'payment_details' => [
                        'amount_cents' => $amountCents,
                        'amount_mxn' => $amountCents / 100,
                        'reference' => $reference,
                        'authorization_code' => $result['authorization_code'] ?? null,
                        'message' => $result['message'] ?? null,
                        'token_type_used' => 'dynamic',
                    ],

                    'processed_at' => now()->toISOString(),
                ],
            ]);

            Log::info('Transacción creada exitosamente', [
                'transaction_id' => $transaction->id,
                'gateway_transaction_id' => $gatewayTransactionId,
            ]);

            return $transaction;

        } catch (\Exception $e) {

            Log::error('Error creando transacción en base de datos', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
            ]);

            throw new EfevooPaymentException(
                'Error al guardar la transacción en la base de datos: ' . $e->getMessage()
            );
        }
    }
}
