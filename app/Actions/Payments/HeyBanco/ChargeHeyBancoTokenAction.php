<?php

namespace App\Actions\Payments\HeyBanco;

use App\Exceptions\HeyBancoPaymentException;
use App\Models\Customer;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Services\Payments\HeyBanco\HeyBancoClient;
use App\Services\Payments\HeyBanco\HeyBancoResponse;
use App\Support\PaymentMethodIdentifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChargeHeyBancoTokenAction
{
    public function __construct(
        private HeyBancoClient $client,
        private VerifyHeyBancoTransactionAction $verifyAction,
    ) {}

    public function __invoke(
        Customer $customer,
        int $amountCents,
        string $paymentMethodId,
        ?string $reference = null,
    ): Transaction {
        if (! PaymentMethodIdentifier::isHeyBanco($paymentMethodId)) {
            throw new HeyBancoPaymentException('Método de pago Hey Banco inválido.');
        }

        $methodId = PaymentMethodIdentifier::heyBancoId($paymentMethodId);

        $paymentMethod = PaymentMethod::query()
            ->active()
            ->forProvider(config('heybanco.provider_key'))
            ->where('user_id', $customer->user_id)
            ->where('id', $methodId)
            ->first();

        if (! $paymentMethod) {
            throw new HeyBancoPaymentException(
                'El método de pago seleccionado no está disponible o ha expirado.'
            );
        }

        if ($paymentMethod->isExpired()) {
            throw new HeyBancoPaymentException(
                'El método de pago ha expirado. Por favor selecciona otro.'
            );
        }

        if (empty($paymentMethod->provider_token)) {
            throw new HeyBancoPaymentException(
                'El token de tarjeta no está disponible. La tarjeta necesita ser tokenizada nuevamente.'
            );
        }

        $reference = $reference ?? ('FM-' . $customer->id . '-' . time() . '-' . rand(1000, 9999));
        $amount = $amountCents / 100;
        $attempt = null;
        $paymentTransaction = null;

        try {
            $attempt = PaymentAttempt::create([
                'customer_id' => $customer->id,
                'token_id' => $paymentMethod->id,
                'amount_cents' => $amountCents,
                'gateway' => config('heybanco.provider_key'),
                'reference' => $reference,
                'status' => 'processing',
            ]);

            $response = $this->client->chargeToken(
                token: $paymentMethod->provider_token,
                amount: $amount,
                reference: $reference,
                metadata: ['ref_cliente2' => (string) $customer->id],
            );

            $paymentTransaction = $this->persistGatewayTransaction(
                customer: $customer,
                paymentMethod: $paymentMethod,
                response: $response,
                amount: $amount,
                reference: $reference,
            );

            if ($response->isTimeout()) {
                $paymentTransaction = $this->handleTimeout(
                    customer: $customer,
                    paymentMethod: $paymentMethod,
                    paymentTransaction: $paymentTransaction,
                    response: $response,
                );
            }

            $this->syncAttempt($attempt, $response, $paymentTransaction);

            if (! $paymentTransaction || $paymentTransaction->status !== 'approved') {
                throw new HeyBancoPaymentException(
                    $response->texto() ?? 'El pago fue rechazado por Hey Banco.',
                    $response->codigoRechazo(),
                    $response->texto(),
                );
            }

            $paymentMethod->update(['last_used_at' => now()]);

            return $this->createBusinessTransaction(
                customer: $customer,
                paymentMethod: $paymentMethod,
                paymentTransaction: $paymentTransaction,
                amountCents: $amountCents,
                reference: $reference,
                response: $response,
                attempt: $attempt,
            );
        } catch (HeyBancoPaymentException $e) {
            if ($attempt) {
                $attempt->update([
                    'status' => 'error',
                    'processor_message' => $e->getMessage(),
                    'processor_code' => $e->processorCode,
                    'processed_at' => now(),
                ]);
            }

            throw $e;
        } catch (\Throwable $e) {
            Log::error('[HeyBanco] Error en cobro', [
                'customer_id' => $customer->id,
                'payment_method_id' => $paymentMethod->id,
                'error' => $e->getMessage(),
            ]);

            if ($attempt) {
                $attempt->update([
                    'status' => 'error',
                    'processor_message' => $e->getMessage(),
                    'processed_at' => now(),
                ]);
            }

            throw new HeyBancoPaymentException(
                'Error al procesar el pago con Hey Banco: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function handleTimeout(
        Customer $customer,
        PaymentMethod $paymentMethod,
        PaymentTransaction $paymentTransaction,
        HeyBancoResponse $response,
    ): PaymentTransaction {
        if ($response->referencia()) {
            $verification = $this->verifyAction->byReference(
                user: $customer->user,
                reference: $response->referencia(),
                mediaId: $paymentMethod->media_id,
                previousTransaction: $paymentTransaction,
            );

            if ($verification->status === 'approved') {
                return $paymentTransaction->fresh();
            }
        }

        if ($paymentTransaction->folio) {
            $verification = $this->verifyAction->byFolio(
                user: $customer->user,
                folio: $paymentTransaction->folio,
                mediaId: $paymentMethod->media_id,
                previousTransaction: $paymentTransaction,
            );

            if ($verification->status === 'approved') {
                return $paymentTransaction->fresh();
            }
        }

        $paymentTransaction->update(['status' => 'timeout']);

        return $paymentTransaction;
    }

    private function persistGatewayTransaction(
        Customer $customer,
        PaymentMethod $paymentMethod,
        HeyBancoResponse $response,
        float $amount,
        string $reference,
    ): PaymentTransaction {
        return PaymentTransaction::create([
            'user_id' => $customer->user_id,
            'payment_method_id' => $paymentMethod->id,
            'provider' => config('heybanco.provider_key'),
            'flow' => 'token_charge',
            'folio' => $response->folio(),
            'reference' => $response->referencia(),
            'auth_code' => $response->codigoAut(),
            'amount' => $amount,
            'currency' => config('heybanco.currency', 'MXN'),
            'mode' => config('heybanco.mode'),
            'status' => $response->isApproved() ? 'approved' : $response->statusLabel(),
            'bnrg_codigo_proc' => $response->codigoProc(),
            'bnrg_codigo_proc_trans' => $response->codigoProcTrans(),
            'bnrg_codigo_rechazo' => $response->codigoRechazo(),
            'bnrg_texto' => $response->texto(),
            'bnrg_estado_trans' => $response->estadoTrans(),
            'bnrg_tipo_trans' => $response->tipoTrans(),
            'raw_request' => $response->rawRequest,
            'raw_response_headers' => $response->normalizedHeaders,
        ]);
    }

    private function syncAttempt(
        PaymentAttempt $attempt,
        HeyBancoResponse $response,
        ?PaymentTransaction $paymentTransaction,
    ): void {
        $attempt->update([
            'status' => $paymentTransaction?->status === 'approved' ? 'approved' : 'declined',
            'processor_code' => $response->codigoProc(),
            'processor_message' => $response->texto(),
            'processor_transaction_id' => $response->referencia(),
            'raw_response' => $response->toArray(),
            'processed_at' => now(),
        ]);
    }

    private function createBusinessTransaction(
        Customer $customer,
        PaymentMethod $paymentMethod,
        PaymentTransaction $paymentTransaction,
        int $amountCents,
        string $reference,
        HeyBancoResponse $response,
        PaymentAttempt $attempt,
    ): Transaction {
        return Transaction::create([
            'transaction_amount_cents' => $amountCents,
            'payment_method' => config('heybanco.provider_key'),
            'reference_id' => $reference,
            'gateway' => config('heybanco.provider_key'),
            'gateway_transaction_id' => $response->referencia() ?? $paymentTransaction->folio,
            'gateway_status' => 'completed',
            'gateway_response' => $response->toArray(),
            'gateway_token' => 'hb-token-ref:' . substr(md5($paymentMethod->provider_token), 0, 20),
            'gateway_processed_at' => now(),
            'gateway_authorization_code' => $response->codigoAut(),
            'details' => [
                'description' => 'Pago procesado con Hey Banco',
                'customer_info' => [
                    'customer_id' => $customer->id,
                    'user_id' => $customer->user_id,
                ],
                'token_info' => [
                    'payment_method_id' => $paymentMethod->id,
                    'card_brand' => $paymentMethod->brand,
                    'card_last_four' => $paymentMethod->last4,
                    'alias' => $paymentMethod->alias,
                ],
                'payment_details' => [
                    'amount_cents' => $amountCents,
                    'amount_mxn' => $amountCents / 100,
                    'reference' => $reference,
                    'banregio_reference' => $response->referencia(),
                    'authorization_code' => $response->codigoAut(),
                    'folio' => $paymentTransaction->folio,
                    'payment_attempt_id' => $attempt->id,
                    'payment_transaction_id' => $paymentTransaction->id,
                ],
                'processed_at' => now()->toISOString(),
            ],
        ]);
    }
}
