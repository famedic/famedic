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
        // Declarar variables fuera del try para que estén disponibles
        $chargeData = [];
        $token = null;
        $result = null;

        try {
            Log::info('ChargeEfevooPaymentMethodAction - Iniciando', [
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
                'payment_method_input' => $paymentMethod,
                'amount_mxn' => $amountCents / 100,
                'environment' => config('efevoopay.environment', 'test'),
            ]);

            // IMPORTANTE: Si es Odessa, manejar de manera diferente
            if ($paymentMethod === 'odessa') {
                // Esto debe ser manejado por ChargeOdessaAction, no por aquí
                // Si llegas aquí, es un error en el flujo
                Log::error('Odessa llegó a ChargeEfevooPaymentMethodAction', [
                    'customer_id' => $customer->id,
                    'payment_method' => $paymentMethod,
                ]);
                throw new \Exception('El método de pago Odessa debe ser manejado por ChargeOdessaAction');
            }

            // Verificar si el paymentMethod es un ID válido de EfevooToken
            if (empty($paymentMethod)) {
                throw new EfevooPaymentException('Token de pago inválido o vacío');
            }

            // Convertir a string para consistencia
            $tokenId = (string) $paymentMethod;

            // Buscar el token de EfevooPay en la base de datos
            $token = $customer->efevooTokens()
                ->active()
                ->where('id', $tokenId)
                ->first();

            if (!$token) {
                Log::error('Token de EfevooPay no encontrado o inactivo', [
                    'customer_id' => $customer->id,
                    'token_id_buscado' => $tokenId,
                    'tokens_activos_disponibles' => $customer->efevooTokens()->active()->count(),
                    'todos_los_tokens' => $customer->efevooTokens()->pluck('id')->toArray(),
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

            // Obtener el card_token (token de tarjeta tokenizada)
            $cardToken = $token->card_token;

            if (empty($cardToken)) {
                Log::error('Card token vacío en EfevooToken', [
                    'token_id' => $token->id,
                    'has_client_token' => !empty($token->client_token),
                    'has_card_token' => !empty($token->card_token),
                    'card_token_value' => $token->card_token,
                ]);
                throw new EfevooPaymentException('El token de tarjeta no está disponible. La tarjeta necesita ser tokenizada nuevamente.');
            }

            Log::info('Token de tarjeta obtenido exitosamente', [
                'token_id' => $token->id,
                'card_token_preview' => substr($cardToken, 0, 30) . '...',
                'card_token_length' => strlen($cardToken),
                'card_last_four' => $token->card_last_four,
                'card_brand' => $token->card_brand,
                'alias' => $token->alias,
                'note' => 'Usando card_token para el pago (token de tarjeta tokenizada)',
            ]);

            // Preparar datos para el cargo
            // NOTA: El reference debe ser único para cada transacción
            $reference = 'LAB-' . $customer->id . '-' . time() . '-' . rand(1000, 9999);

            $chargeData = [
                'token_id' => $cardToken, // Token de tarjeta tokenizada
                'amount' => $amountCents / 100, // Convertir centavos a MXN
                'description' => 'Compra de estudios de laboratorio - Famedic',
                'reference' => $reference,
                'customer_id' => $customer->id,
                'metadata' => [
                    'customer_id' => $customer->id,
                    'user_id' => $customer->user_id,
                    'token_id' => $token->id,
                    'efevoo_token_id' => $token->id,
                    'source' => 'laboratory_checkout',
                    'card_last_four' => $token->card_last_four,
                    'card_brand' => $token->card_brand,
                ],
            ];

            Log::info('Realizando cargo con EfevooPay - Datos preparados', [
                'charge_data_masked' => $this->maskSensitiveData($chargeData),
                'token_info' => [
                    'id' => $token->id,
                    'card_last_four' => $token->card_last_four,
                    'card_brand' => $token->card_brand,
                    'environment' => $token->environment,
                    'alias' => $token->alias,
                    'card_token_preview' => substr($cardToken, 0, 30) . '...',
                ],
                'amount_details' => [
                    'cents' => $amountCents,
                    'mxn' => $amountCents / 100,
                    'formatted' => number_format($amountCents / 100, 2, '.', ''),
                ],
            ]);

            // Realizar el cargo usando el servicio
            // IMPORTANTE: El servicio ahora usará token dinámico automáticamente
            $result = $this->efevooPayService->chargeCard($chargeData);

            Log::info('Respuesta recibida de EfevooPay', [
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? 'Sin mensaje',
                'has_transaction_id' => isset($result['transaction_id']),
                'has_efevoo_id' => isset($result['efevoo_transaction_id']),
                'has_authorization' => isset($result['authorization_code']),
                'method_used' => $result['method_used'] ?? 'getPayment',
                'code' => $result['code'] ?? null,
                'customer_id' => $customer->id,
                'reference' => $reference,
            ]);

            // Validar resultado del cargo
            if (!$result['success']) {
                $errorMessage = $result['message'] ?? 'Error al procesar el pago';
                $errorCode = $result['code'] ?? null;

                Log::error('Error en cargo EfevooPay - Pago rechazado', [
                    'result' => $result,
                    'customer_id' => $customer->id,
                    'amount_cents' => $amountCents,
                    'amount_mxn' => $amountCents / 100,
                    'token_id' => $token->id,
                    'reference' => $reference,
                ]);

                throw new EfevooPaymentException($errorMessage, $errorCode, $result);
            }

            // Validar que tengamos un ID de transacción
            $transactionId = $result['transaction_id'] ?? $result['efevoo_transaction_id'] ?? null;
            if (empty($transactionId)) {
                Log::error('Respuesta de EfevooPay sin ID de transacción', [
                    'result' => $result,
                    'customer_id' => $customer->id,
                    'reference' => $reference,
                ]);
                throw new EfevooPaymentException('La transacción no generó un ID válido.');
            }

            Log::info('Cargo exitoso con EfevooPay', [
                'transaction_id' => $transactionId,
                'authorization_code' => $result['authorization_code'] ?? null,
                'customer_id' => $customer->id,
                'amount_mxn' => $amountCents / 100,
                'method_used' => $result['method_used'] ?? 'getPayment',
                'code' => $result['code'] ?? null,
                'reference' => $reference,
                'note' => '✅ Pago procesado exitosamente con token dinámico',
            ]);

        } catch (EfevooPaymentException $e) {
            // Relanzar excepción específica para que sea manejada por el controlador
            throw $e;
        } catch (\Exception $e) {
            Log::error('Excepción en ChargeEfevooPaymentMethodAction', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
                'payment_method_input' => $paymentMethod,
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw new EfevooPaymentException(
                'Error al procesar el pago con EfevooPay: ' . $e->getMessage(),
                null,
                ['original_exception' => $e->getMessage()]
            );
        }

        // Crear transacción en la base de datos (fuera del try para que use variables definidas)
        try {
            // Usar el transaction_id de la respuesta o generar uno alternativo
            $gatewayTransactionId = $result['efevoo_transaction_id'] ?? $result['transaction_id'] ?? $transactionId ?? 'EFV-' . time();

            // Preparar datos para la transacción
            $transactionData = [
                'transaction_amount_cents' => $amountCents,
                'payment_method' => 'efevoopay',
                'reference_id' => $reference,
                'gateway' => 'efevoopay',
                'gateway_transaction_id' => $gatewayTransactionId,
                'gateway_status' => 'completed', // O podrías usar $result['status'] si existe
                'gateway_response' => json_encode($result, JSON_UNESCAPED_UNICODE),
                //'gateway_token' => $cardToken, // Guardar el card_token usado
                'gateway_token' => 'efv-token-ref:' . substr(md5($cardToken), 0, 20),
                //'gateway_authorization_code' => $result['authorization_code'] ?? null,
                'gateway_processed_at' => now(),
                'description' => $chargeData['description'] ?? 'Compra de estudios de laboratorio',
                'details' => json_encode([
                    'customer_info' => [
                        'customer_id' => $customer->id,
                        'user_id' => $customer->user_id ?? null,
                        'customer_name' => $customer->name ?? null,
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
                        'method_used' => $result['method_used'] ?? 'getPayment',
                        'token_type_used' => 'dynamic', // IMPORTANTE: Registrar que usamos token dinámico
                        'code' => $result['code'] ?? null,
                        'message' => $result['message'] ?? null,
                    ],
                    'efevoo_response' => $result['data'] ?? [],
                    'metadata' => $chargeData['metadata'] ?? [],
                    'simulated' => false, // Siempre false para pagos reales
                    'processed_at' => now()->toISOString(),
                ], JSON_UNESCAPED_UNICODE),
            ];

            Log::info('Creando registro de transacción en base de datos', [
                'transaction_data_masked' => $this->maskSensitiveData($transactionData),
                'customer_id' => $customer->id,
                'efevoo_transaction_id' => $gatewayTransactionId,
                'method_used' => $result['method_used'] ?? 'getPayment',
                'reference' => $reference,
            ]);

            // Crear la transacción
            $transaction = Transaction::create($transactionData);

            Log::info('Transacción creada exitosamente en base de datos', [
                'transaction_id' => $transaction->id,
                'gateway_transaction_id' => $transaction->gateway_transaction_id,
                'customer_id' => $customer->id,
                'amount_cents' => $amountCents,
                'reference' => $reference,
                'authorization_code' => $result['authorization_code'] ?? null,
                'method_used' => $result['method_used'] ?? 'getPayment',
            ]);

            // Opcional: Actualizar el token con información de la transacción
            try {
                $metadata = $token->metadata ?? [];
                $metadata['last_transaction'] = [
                    'transaction_id' => $transaction->id,
                    'efevoo_transaction_id' => $gatewayTransactionId,
                    'amount' => $amountCents / 100,
                    'date' => now()->toISOString(),
                    'reference' => $reference,
                ];

                $token->update([
                    'last_used_at' => now(),
                    'metadata' => $metadata,
                ]);

                Log::debug('Token actualizado con información de transacción', [
                    'token_id' => $token->id,
                    'last_used_at' => now()->toISOString(),
                ]);
            } catch (\Exception $e) {
                Log::warning('Error al actualizar token con información de transacción', [
                    'error' => $e->getMessage(),
                    'token_id' => $token->id,
                ]);
                // No lanzar excepción, esto no es crítico
            }

            return $transaction;

        } catch (\Exception $e) {
            Log::error('Error creando transacción en base de datos', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
                'charge_data_masked' => $this->maskSensitiveData($chargeData),
                'result_summary' => [
                    'success' => $result['success'] ?? false,
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'code' => $result['code'] ?? null,
                ],
                'trace' => $e->getTraceAsString(),
            ]);

            throw new EfevooPaymentException(
                'Error al guardar la transacción en la base de datos: ' . $e->getMessage()
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