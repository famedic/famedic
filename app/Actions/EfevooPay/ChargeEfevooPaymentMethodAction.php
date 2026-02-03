<?php

namespace App\Actions\EfevooPay;

use App\Models\Customer;
use App\Models\Transaction;
use App\Services\EfevooPayFactoryService;
use Illuminate\Support\Facades\Log;
use App\Exceptions\EfevooPaymentException;

class ChargeEfevooPaymentMethodAction
{
    protected EfevooPayFactoryService $efevooPayService;

    public function __construct(EfevooPayFactoryService $efevooPayService)
    {
        $this->efevooPayService = $efevooPayService;
    }

    public function __invoke(Customer $customer, int $amountCents, string $paymentMethod): Transaction
    {
        // Declarar variables fuera del try para que estén disponibles
        $chargeData = [];
        $token = null;
        $result = null;
        
        try {
            Log::info('Iniciando cargo con EfevooPay', [
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
                'payment_method' => $paymentMethod,
                'amount_mxn' => $amountCents / 100,
            ]);

            // Si es Odessa, manejar de manera diferente
            if ($paymentMethod === 'odessa') {
                throw new \Exception('Odessa debe ser manejado por ChargeOdessaAction');
            }

            // Verificar si el paymentMethod es un ID válido
            if (empty($paymentMethod)) {
                throw new EfevooPaymentException('Token de pago inválido o vacío');
            }

            // Convertir a string para consistencia
            $tokenId = (string) $paymentMethod;

            // Buscar el token en la base de datos
            $token = $customer->efevooTokens()
                ->active()
                ->where('id', $tokenId)
                ->first();

            if (!$token) {
                Log::error('Token de EfevooPay no encontrado o inactivo', [
                    'customer_id' => $customer->id,
                    'token_id' => $tokenId,
                    'tokens_activos' => $customer->efevooTokens()->active()->count(),
                ]);
                throw new EfevooPaymentException('El método de pago seleccionado no está disponible o ha expirado.');
            }

            // Verificar que el token no esté expirado
            if ($token->isExpired()) {
                Log::warning('Token de EfevooPay expirado', [
                    'token_id' => $token->id,
                    'expires_at' => $token->expires_at,
                    'customer_id' => $customer->id,
                ]);
                throw new EfevooPaymentException('El método de pago ha expirado. Por favor selecciona otro.');
            }

            // Preparar datos para el cargo
            $chargeData = [
                'token_id' => $token->client_token ?? $token->id,
                'amount' => $amountCents / 100,
                'description' => 'Compra de estudios de laboratorio - Famedic',
                'customer_id' => $customer->id,
                'reference' => 'LAB-' . time() . '-' . $customer->id,
                'metadata' => [
                    'customer_id' => $customer->id,
                    'user_id' => $customer->user_id,
                    'token_id' => $token->id,
                    'source' => 'laboratory_checkout',
                ],
            ];

            Log::info('Realizando cargo con EfevooPay', [
                'charge_data' => $this->maskSensitiveData($chargeData),
                'token_info' => [
                    'id' => $token->id,
                    'card_last_four' => $token->card_last_four,
                    'card_brand' => $token->card_brand,
                    'environment' => $token->environment,
                ],
            ]);

            // Realizar el cargo
            $result = $this->efevooPayService->chargeCard($chargeData);

            Log::info('Respuesta de EfevooPay', [
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? 'Sin mensaje',
                'has_transaction_id' => isset($result['transaction_id']),
                'has_authorization' => isset($result['authorization_code']),
            ]);

            if (!$result['success']) {
                $errorMessage = $result['message'] ?? 'Error al procesar el pago';
                $errorCode = $result['code'] ?? null;
                
                Log::error('Error en cargo EfevooPay', [
                    'result' => $result,
                    'customer_id' => $customer->id,
                    'amount' => $amountCents,
                    'token_id' => $token->id,
                ]);
                
                throw new EfevooPaymentException($errorMessage, $errorCode, $result);
            }

            // Validar respuesta
            if (empty($result['transaction_id'])) {
                Log::error('Respuesta de EfevooPay sin transaction_id', [
                    'result' => $result,
                    'customer_id' => $customer->id,
                ]);
                throw new EfevooPaymentException('La transacción no generó un ID válido.');
            }

            Log::info('Cargo exitoso con EfevooPay', [
                'transaction_id' => $result['transaction_id'],
                'authorization_code' => $result['authorization_code'] ?? null,
                'customer_id' => $customer->id,
                'amount_mxn' => $amountCents / 100,
            ]);

        } catch (EfevooPaymentException $e) {
            // Relanzar excepción específica
            throw $e;
        } catch (\Exception $e) {
            Log::error('Excepción en ChargeEfevooPaymentMethodAction', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
                'payment_method' => $paymentMethod,
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw new EfevooPaymentException(
                'Error al procesar el pago: ' . $e->getMessage(),
                null,
                ['original_exception' => $e->getMessage()]
            );
        }

        // Crear transacción en la base de datos (fuera del try para que use variables definidas)
        try {
            // Preparar datos para la transacción según tu estructura
            $transactionData = [
                'transaction_amount_cents' => $amountCents,
                'payment_method' => 'efevoopay',
                'reference_id' => $chargeData['reference'] ?? 'LAB-' . time() . '-' . $customer->id,
                'gateway' => 'efevoopay',
                'gateway_transaction_id' => $result['transaction_id'],
                'gateway_status' => $result['status'] ?? 'completed',
                'gateway_response' => json_encode($result),
                'gateway_token' => $token->client_token ?? $token->id,
                'gateway_processed_at' => now(),
                'details' => json_encode([
                    'customer_id' => $customer->id,
                    'user_id' => $customer->user_id ?? null,
                    'token_id' => $token->id,
                    'card_brand' => $token->card_brand,
                    'card_last_four' => $token->card_last_four,
                    'environment' => $token->environment,
                    'authorization_code' => $result['authorization_code'] ?? null,
                    'description' => $chargeData['description'] ?? 'Compra de estudios de laboratorio',
                    'metadata' => $chargeData['metadata'] ?? [],
                    'simulated' => $result['simulated'] ?? false,
                ]),
            ];

            // Agregar descripción si está disponible
            if (isset($chargeData['description'])) {
                $transactionData['description'] = $chargeData['description'];
            }

            Log::info('Creando transacción con estructura existente', [
                'transaction_data' => $this->maskSensitiveData($transactionData),
                'customer_id' => $customer->id,
            ]);

            // Crear la transacción
            $transaction = Transaction::create($transactionData);

            Log::info('Transacción creada exitosamente', [
                'transaction_id' => $transaction->id,
                'gateway_transaction_id' => $transaction->gateway_transaction_id,
                'customer_id' => $customer->id,
            ]);

            return $transaction;

        } catch (\Exception $e) {
            Log::error('Error creando transacción en base de datos', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
                'charge_data' => $this->maskSensitiveData($chargeData),
                'result' => $result,
            ]);
            
            throw new EfevooPaymentException(
                'Error al guardar la transacción: ' . $e->getMessage()
            );
        }
    }

    /**
     * Enmascarar datos sensibles para logging
     */
    private function maskSensitiveData(array $data): array
    {
        $masked = $data;
        
        if (isset($masked['token_id'])) {
            $masked['token_id'] = substr($masked['token_id'], 0, 8) . '...';
        }
        
        if (isset($masked['card_number'])) {
            $masked['card_number'] = '**** **** **** ' . substr($masked['card_number'], -4);
        }
        
        if (isset($masked['gateway_token'])) {
            $masked['gateway_token'] = substr($masked['gateway_token'], 0, 8) . '...';
        }
        
        if (isset($masked['gateway_response']) && is_string($masked['gateway_response'])) {
            $response = json_decode($masked['gateway_response'], true);
            if ($response && isset($response['token_id'])) {
                $response['token_id'] = substr($response['token_id'], 0, 8) . '...';
                $masked['gateway_response'] = json_encode($response);
            }
        }
        
        return $masked;
    }
}