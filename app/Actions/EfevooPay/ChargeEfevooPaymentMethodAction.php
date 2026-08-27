<?php

namespace App\Actions\EfevooPay;

use App\Models\Customer;
use App\Models\Cart;
use App\Models\Transaction;
use App\Models\PaymentAttempt;
use App\Contracts\EfevooPayGateway;
use App\Enums\CartEventType;
use App\Services\Carts\CartAbandonmentService;
use App\Services\Carts\CartEventRecorder;
use App\Support\MockEfevooPaymentSupport;
use App\Services\Payments\PaymentAutomationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Exceptions\EfevooPaymentException;

class ChargeEfevooPaymentMethodAction
{
    protected EfevooPayGateway $efevooPayService;

    public function __construct(
        EfevooPayGateway $efevooPayService,
        private PaymentAutomationService $paymentAutomationService,
        private CartEventRecorder $cartEventRecorder,
    ) {
        $this->efevooPayService = $efevooPayService;
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    public function __invoke(Customer $customer, int $amountCents, string $paymentMethod, ?Cart $cart = null, ?array $clientContext = null): Transaction
    {
        $chargeData = [];
        $token = null;
        $result = null;
        $attempt = null;
        $reference = null;
        $cardToken = null;

        try {
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

                if (! MockEfevooPaymentSupport::isMockMode() && MockEfevooPaymentSupport::isMockToken($token)) {
                    throw new EfevooPaymentException(
                        'No se puede pagar con una tarjeta de prueba en ambiente productivo.'
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
                $attemptPayload = [
                    'customer_id' => $customer->id,
                    'token_id' => $token->id,
                    'amount_cents' => $amountCents,
                    'gateway' => 'efevoopay',
                    'reference' => $reference,
                    'status' => PaymentAttempt::STATUS_PROCESSING,
                ];

                if ($cart && Schema::hasColumn('payment_attempts', 'cart_id')) {
                    $attemptPayload['cart_id'] = $cart->id;
                }

                $attempt = PaymentAttempt::create($attemptPayload);

                $this->recordPaymentEventForAttempt($attempt, CartEventType::PaymentStarted, $cart, $clientContext);

                Log::info('[EfevooPay] PaymentAttempt creado, llamando al gateway', [
                    'attempt_id' => $attempt->id,
                    'reference' => $reference,
                    'customer_id' => $customer->id,
                ]);

                $result = $this->efevooPayService->chargeCard($chargeData);

                // Extraer respuesta del gateway para el intento
                $rawData = $result['raw']['data'] ?? $result['raw'] ?? [];
                $processorCode = $result['error_code']
                    ?? $rawData['codigo']
                    ?? null;
                $processorMessage = $result['message']
                    ?? $rawData['descripcion']
                    ?? $rawData['msg']
                    ?? null;
                $processorTransactionId = $result['transaction_id']
                    ?? $rawData['id']
                    ?? $rawData['numtxn']
                    ?? null;

                $attemptStatus = $this->resolveAttemptStatusFromGatewayResult($result);

                $attempt->update([
                    'status' => $attemptStatus,
                    'processor_code' => $processorCode !== null ? (string) $processorCode : null,
                    'processor_message' => is_string($processorMessage) ? $processorMessage : json_encode($processorMessage),
                    'processor_transaction_id' => $processorTransactionId,
                    'raw_response' => $result['raw'] ?? null,
                    'processed_at' => now(),
                ]);

                $this->recordPaymentEventForAttempt($attempt->refresh(), match ($attemptStatus) {
                    PaymentAttempt::STATUS_APPROVED => CartEventType::PaymentApproved,
                    PaymentAttempt::STATUS_DECLINED => CartEventType::PaymentDeclined,
                    default => CartEventType::PaymentError,
                }, $cart);

                Log::info('[EfevooPay] PaymentAttempt actualizado con respuesta del gateway', [
                    'attempt_id' => $attempt->id,
                    'status' => $attempt->status,
                    'processor_code' => $processorCode,
                    'processor_transaction_id' => $processorTransactionId,
                    'error_type' => $result['error_type'] ?? null,
                ]);

                if (!$result['success']) {
                    $message = $attemptStatus === PaymentAttempt::STATUS_DECLINED
                        ? \App\Support\PaymentErrorClassifier::message(
                            $processorCode !== null ? (string) $processorCode : null
                        )
                        : ($result['message'] ?? 'Error al procesar el pago con EfevooPay.');

                    throw new EfevooPaymentException(
                        $message,
                        $processorCode !== null ? (string) $processorCode : null
                    );
                }

                $gatewayTransactionId =
                    $result['transaction_id']
                    ?? $result['efevoo_transaction_id']
                    ?? $rawData['id']
                    ?? 'EFV-' . time();

            } catch (EfevooPaymentException $e) {

                // Do not overwrite declined / approved / error / refunded set from the gateway response.
                $this->markAttemptAsTechnicalErrorIfStillOpen($attempt, $e->getMessage(), [
                    'processor_code' => $e->getEfevooErrorCode(),
                ]);

                throw $e;
            } catch (\Exception $e) {

                Log::error('[EfevooPay] Excepción en ChargeEfevooPaymentMethodAction', [
                    'error' => $e->getMessage(),
                    'customer_id' => $customer->id,
                    'attempt_id' => $attempt?->id,
                ]);

                $this->markAttemptAsTechnicalErrorIfStillOpen($attempt, $e->getMessage(), [
                    'raw_response' => ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()],
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
        } finally {
            $this->dispatchPaymentAutomation($attempt);
        }
    }

    /**
     * Single integration point: route finalized PaymentAttempt to PaymentAutomationService.
     */
    private function dispatchPaymentAutomation(?PaymentAttempt $attempt): void
    {
        if (! $attempt) {
            return;
        }

        try {
            $attempt->refresh();
        } catch (\Throwable) {
            return;
        }

        if (! $attempt->isFinalized()) {
            return;
        }

        try {
            match ($attempt->status) {
                PaymentAttempt::STATUS_APPROVED => $this->paymentAutomationService->handleApproved($attempt),
                PaymentAttempt::STATUS_DECLINED => $this->paymentAutomationService->handleDeclined($attempt),
                PaymentAttempt::STATUS_ERROR => $this->paymentAutomationService->handleError($attempt),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('[PaymentAutomation] Failed to dispatch from ChargeEfevooPaymentMethodAction', [
                'attempt_id' => $attempt->id,
                'status' => $attempt->status,
                'error' => $e->getMessage(),
            ]);
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

    /**
     * Map gateway chargeCard() payload to PaymentAttempt status.
     *
     * approved  — gateway authorized the charge
     * declined  — bank/processor rejected (never treated as technical error)
     * error     — network/timeout/system/gateway infra failures
     */
    private function resolveAttemptStatusFromGatewayResult(array $result): string
    {
        if (! empty($result['success'])) {
            return PaymentAttempt::STATUS_APPROVED;
        }

        $errorType = $result['error_type'] ?? null;

        if (in_array($errorType, ['network', 'system'], true)) {
            return PaymentAttempt::STATUS_ERROR;
        }

        // Mock gateway bank declines use error_type=bank
        if ($errorType === 'bank') {
            return PaymentAttempt::STATUS_DECLINED;
        }

        // Production chargeCard() sets error_code only on processor decline (codigo !== 00)
        $errorCode = $result['error_code'] ?? null;
        if ($errorCode !== null && $errorCode !== '' && (string) $errorCode !== '00') {
            return PaymentAttempt::STATUS_DECLINED;
        }

        // Client-token failures, empty payloads, HTTP infra issues without bank codes → error
        return PaymentAttempt::STATUS_ERROR;
    }

    /**
     * Only processing (or non-final) attempts become error on technical exceptions.
     * Preserves declined / approved / error / refunded already written from the gateway path.
     *
     * @param  array<string, mixed>  $extra
     */
    private function markAttemptAsTechnicalErrorIfStillOpen(?PaymentAttempt $attempt, string $message, array $extra = []): void
    {
        if (! $attempt) {
            return;
        }

        if ($attempt->isFinalized()) {
            Log::info('[EfevooPay] PaymentAttempt status preserved after exception', [
                'attempt_id' => $attempt->id,
                'status' => $attempt->status,
                'message' => $message,
            ]);

            return;
        }

        $attempt->update(array_merge([
            'status' => PaymentAttempt::STATUS_ERROR,
            'processor_message' => $message,
            'processed_at' => now(),
        ], array_filter($extra, fn ($value) => $value !== null)));

        $this->recordPaymentEventForAttempt($attempt->refresh(), CartEventType::PaymentError);

        Log::info('[EfevooPay] PaymentAttempt marked as technical error', [
            'attempt_id' => $attempt->id,
            'status' => PaymentAttempt::STATUS_ERROR,
            'message' => $message,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $clientContext
     */
    private function recordPaymentEventForAttempt(
        PaymentAttempt $attempt,
        CartEventType $event,
        ?Cart $fallbackCart = null,
        ?array $clientContext = null,
    ): void {
        $cart = $fallbackCart ?? $attempt->cart;

        if (! $cart) {
            return;
        }

        app(CartAbandonmentService::class)->maybeRecordResumed($cart, $clientContext);

        $this->cartEventRecorder->recordOnce(
            $cart,
            $event,
            "payment_attempt:{$attempt->id}:{$event->value}",
            $this->withClientContext([
                'payment_attempt_id' => $attempt->id,
                'gateway' => $attempt->gateway,
                'status' => $attempt->status,
                'processor_code' => $attempt->processor_code,
                'amount_cents' => $attempt->amount_cents,
            ], $clientContext),
            $attempt->processed_at ?? $attempt->updated_at ?? $attempt->created_at,
            'efevoopay',
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $clientContext
     * @return array<string, mixed>
     */
    private function withClientContext(array $metadata, ?array $clientContext): array
    {
        if ($clientContext === null || $clientContext === []) {
            return $metadata;
        }

        return array_merge($metadata, ['client' => $clientContext]);
    }
}
