<?php

namespace App\Actions\Payments\HeyBanco;

use App\Exceptions\HeyBancoPaymentException;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Services\Payments\HeyBanco\HeyBancoClient;
use App\Services\Payments\HeyBanco\HeyBancoResponse;

class CancelHeyBancoTransactionAction
{
    public function __construct(
        private HeyBancoClient $client,
    ) {}

    public function __invoke(Transaction $transaction, ?Customer $customer = null): PaymentTransaction
    {
        $reference = $this->resolveBanregioReference($transaction);

        if (! $reference) {
            throw new HeyBancoPaymentException(
                'No hay referencia Banregio asociada a esta transacción para cancelar.'
            );
        }

        $mediaId = $this->resolveMediaId($transaction);
        $amountCents = (int) $transaction->transaction_amount_cents;
        $response = $this->client->cancelByReference(
            $reference,
            $mediaId,
            $amountCents / 100,
        );

        return $this->persistCancellationTransaction(
            transaction: $transaction,
            customer: $customer,
            previousReference: $reference,
            response: $response,
        );
    }

    private function resolveBanregioReference(Transaction $transaction): ?string
    {
        $details = $this->normalizeDetails($transaction->details);

        $fromDetails = $details['payment_details']['banregio_reference'] ?? null;

        return $fromDetails
            ?: $transaction->gateway_transaction_id
            ?: ($details['payment_details']['reference'] ?? null);
    }

    private function resolveMediaId(Transaction $transaction): ?string
    {
        $paymentMethodId = $this->resolvePaymentMethodId($transaction);

        if ($paymentMethodId) {
            $mediaId = PaymentMethod::query()
                ->where('id', $paymentMethodId)
                ->value('media_id');

            if ($mediaId) {
                return $mediaId;
            }
        }

        return config('heybanco.token_media_id');
    }

    private function resolvePaymentMethodId(Transaction $transaction): ?int
    {
        $details = $this->normalizeDetails($transaction->details);
        $methodId = $details['token_info']['payment_method_id'] ?? null;

        return $methodId ? (int) $methodId : null;
    }

    private function resolveUserId(Transaction $transaction, ?Customer $customer): ?int
    {
        if ($customer?->user_id) {
            return $customer->user_id;
        }

        $details = $this->normalizeDetails($transaction->details);

        return isset($details['customer_info']['user_id'])
            ? (int) $details['customer_info']['user_id']
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeDetails(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }

        if (is_string($details)) {
            return json_decode($details, true) ?? [];
        }

        return [];
    }

    private function persistCancellationTransaction(
        Transaction $transaction,
        ?Customer $customer,
        string $previousReference,
        HeyBancoResponse $response,
    ): PaymentTransaction {
        $amountCents = (int) $transaction->transaction_amount_cents;

        return PaymentTransaction::create([
            'user_id' => $this->resolveUserId($transaction, $customer),
            'payment_method_id' => $this->resolvePaymentMethodId($transaction),
            'provider' => config('heybanco.provider_key'),
            'flow' => 'cancellation',
            'folio' => $response->folio(),
            'reference' => $response->referencia(),
            'previous_reference' => $previousReference,
            'auth_code' => $response->codigoAut(),
            'amount' => $amountCents / 100,
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
}
