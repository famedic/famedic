<?php

namespace App\Actions\EfevooPay;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\PaymentAttempt;
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
        $attempt = null;
        $reference = null;
        $cardToken = null;

        try {

            Log::info('[EfevooPay] ChargeEfevooPaymentMethodAction - Iniciando', [
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
                'card_token' => $cardToken,
                'amount' => $amountCents / 100,
                'reference' => $reference,
            ];

            // Registrar intento ANTES de llamar al gateway (rastreo desde el inicio)
            $attempt = PaymentAttempt::create([
                'customer_id' => $customer->id,
                'token_id' => $token->id,
                'amount_cents' => $amountCents,
                'gateway' => 'efevoopay',
                'reference' => $reference,
                'status' => 'processing',
            ]);

            Log::info('[EfevooPay] PaymentAttempt creado, llamando al gateway', [
                'attempt_id' => $attempt->id,
                'reference' => $reference,
                'customer_id' => $customer->id,
            ]);

            $result = $this->efevooPayService->chargeCard($chargeData);

            // Extraer respuesta del gateway para el intento
            $rawData = $result['raw']['data'] ?? $result['raw'] ?? [];
            $processorCode = $rawData['codigo'] ?? null;
            $processorMessage = $result['message']
                ?? $rawData['descripcion']
                ?? $rawData['msg']
                ?? null;
            $processorTransactionId = $result['transaction_id']
                ?? $rawData['id']
                ?? $rawData['numtxn']
                ?? null;

            $attempt->update([
                'status' => $result['success'] ? 'approved' : 'declined',
                'processor_code' => $processorCode,
                'processor_message' => is_string($processorMessage) ? $processorMessage : json_encode($processorMessage),
                'processor_transaction_id' => $processorTransactionId,
                'raw_response' => $result['raw'] ?? null,
                'processed_at' => now(),
            ]);

            Log::info('[EfevooPay] PaymentAttempt actualizado con respuesta del gateway', [
                'attempt_id' => $attempt->id,
                'status' => $attempt->status,
                'processor_code' => $processorCode,
                'processor_transaction_id' => $processorTransactionId,
            ]);

            if (!$result['success']) {

                $message = \App\Support\PaymentErrorClassifier::message($processorCode);

                throw new \App\Exceptions\EfevooPaymentException(
                    $message,
                    $processorCode
                );
            }

            $gatewayTransactionId =
                $result['transaction_id']
                ?? $result['efevoo_transaction_id']
                ?? $rawData['id']
                ?? 'EFV-' . time();

        } catch (EfevooPaymentException $e) {

            if ($attempt) {
                $attempt->update([
                    'status' => 'error',
                    'processor_message' => $e->getMessage(),
                    'processor_code' => $e->getCode() ?: null,
                    'processed_at' => now(),
                ]);
                Log::info('[EfevooPay] PaymentAttempt actualizado tras excepción de pago', [
                    'attempt_id' => $attempt->id,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

            throw $e;
        } catch (\Exception $e) {

            Log::error('[EfevooPay] Excepción en ChargeEfevooPaymentMethodAction', [
                'error' => $e->getMessage(),
                'customer_id' => $customer->id,
                'attempt_id' => $attempt?->id,
            ]);

            if ($attempt) {
                $attempt->update([
                    'status' => 'error',
                    'processor_message' => $e->getMessage(),
                    'raw_response' => ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()],
                    'processed_at' => now(),
                ]);
            }

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
            $commissionCents = $this->extractCommissionCentsFromGatewayResult($result);

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
                        'commission_source' => 'efevoopay_response',
                    ],
                    'commission_cents' => $commissionCents,
                    'commission_fetched_at' => now()->toIso8601String(),

                    'processed_at' => now()->toISOString(),
                ],
            ]);

            Log::info('[EfevooPay] Transacción creada exitosamente', [
                'transaction_id' => $transaction->id,
                'gateway_transaction_id' => $gatewayTransactionId,
                'payment_attempt_id' => $attempt?->id,
                'reference' => $reference,
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

    private function extractCommissionCentsFromGatewayResult(array $result): int
    {
        $possiblePaths = [
            'raw.data.commission',
            'raw.data.commission_amount',
            'raw.data.commission_mxn',
            'raw.data.transaction_fee',
            'raw.data.fee',
            'raw.data.fee_amount',
            'raw.data.comision',
            'raw.data.comision_total',
            'raw.data.payload.commission',
            'raw.data.payload.fee',
            'raw.commission',
            'raw.fee',
        ];

        foreach ($possiblePaths as $path) {
            $value = data_get($result, $path);
            $cents = $this->parseAmountToCents($value);

            if ($cents !== null) {
                return $cents;
            }
        }

        return 0;
    }

    private function parseAmountToCents(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 1000 ? $value : (int) round($value * 100);
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        if (is_string($value)) {
            $normalized = str_replace([',', '$', 'MXN', 'mxn', ' '], ['', '', '', '', ''], $value);

            if (!is_numeric($normalized)) {
                return null;
            }

            return (int) round(((float) $normalized) * 100);
        }

        if (is_array($value)) {
            $nestedCandidates = [
                $value['value'] ?? null,
                $value['amount'] ?? null,
                $value['total'] ?? null,
            ];

            foreach ($nestedCandidates as $candidate) {
                $cents = $this->parseAmountToCents($candidate);
                if ($cents !== null) {
                    return $cents;
                }
            }
        }

        return null;
    }
}
