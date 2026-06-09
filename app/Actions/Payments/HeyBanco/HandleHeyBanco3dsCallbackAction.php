<?php

namespace App\Actions\Payments\HeyBanco;

use App\Exceptions\HeyBancoPaymentException;
use App\Models\Payment3dsSession;
use App\Models\Transaction;
use App\Services\Payments\HeyBanco\HeyBanco3dsSignatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandleHeyBanco3dsCallbackAction
{
    public function __construct(
        private HeyBanco3dsSignatureService $signatureService,
        private FulfillLaboratoryHeyBanco3dsPaymentAction $fulfillLaboratoryAction,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{session: Payment3dsSession, transaction: ?Transaction, purchase_id: ?int, already_processed: bool}
     */
    public function __invoke(array $payload): array
    {
        $normalized = $this->normalizePayload($payload);
        $folio = $normalized['BNRG_FOLIO'] ?? null;

        if (! $folio) {
            throw new HeyBancoPaymentException('Callback 3DS sin folio.');
        }

        return DB::transaction(function () use ($normalized, $folio) {
            $session = Payment3dsSession::query()
                ->where('provider', config('heybanco.provider_key'))
                ->where('folio', $folio)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new HeyBancoPaymentException('Sesión 3DS no encontrada.');
            }

            if ($session->isApproved()) {
                return [
                    'session' => $session,
                    'transaction' => $this->findBusinessTransaction($session),
                    'purchase_id' => $this->findPurchaseId($session),
                    'already_processed' => true,
                ];
            }

            $hashValid = null;

            if (config('heybanco.3ds_secure_api', true)) {
                $hashValid = $this->signatureService->validateResponse($normalized);

                if (! $hashValid) {
                    $session->update([
                        'hash_valid' => false,
                        'response_hash' => $normalized['BNRG_HASH'] ?? null,
                        'raw_response' => $normalized,
                        'status' => 'failed',
                        'failed_at' => now(),
                    ]);

                    throw new HeyBancoPaymentException('Firma BNRG_HASH inválida en callback 3DS.');
                }
            }

            $codigoProc = strtoupper((string) ($normalized['BNRG_CODIGO_PROC'] ?? ''));
            $status = $this->mapCodigoProcToStatus($codigoProc);

            $session->update([
                'hash_valid' => $hashValid,
                'response_hash' => $normalized['BNRG_HASH'] ?? null,
                'raw_response' => $normalized,
                'bnrg_codigo_proc' => $codigoProc ?: null,
                'bnrg_reference' => $normalized['BNRG_REFERENCIA'] ?? null,
                'auth_code' => $normalized['BNRG_CODIGO_AUT'] ?? null,
                'issuer_code' => $normalized['BNRG_CODIGO_EMISOR'] ?? null,
                'bnrg_text' => $normalized['BNRG_TEXTO'] ?? null,
                'eci' => $normalized['BNRG_3DS_ECI'] ?? null,
                'ucaf' => $normalized['BNRG_3DS_UCAF'] ?? null,
                'xid' => $normalized['BNRG_3DS_XID'] ?? null,
                'bnrg_codigo_rechazo' => $normalized['BNRG_CODIGO_RECHAZO'] ?? null,
            ]);

            $this->syncPaymentRecords($session, $normalized, $status);

            if ($codigoProc !== 'A') {
                $session->markFailed($normalized, $status);

                return [
                    'session' => $session->fresh(),
                    'transaction' => null,
                    'purchase_id' => null,
                    'already_processed' => false,
                ];
            }

            $session->markApproved($normalized);

            $transaction = $this->createBusinessTransaction($session, $normalized);
            $purchase = ($this->fulfillLaboratoryAction)($session, $transaction);

            return [
                'session' => $session->fresh(),
                'transaction' => $transaction,
                'purchase_id' => $purchase?->id,
                'already_processed' => false,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $normalized[strtoupper((string) $key)] = rawurldecode(trim((string) $value));
        }

        return $normalized;
    }

    private function mapCodigoProcToStatus(string $codigoProc): string
    {
        return match ($codigoProc) {
            'A' => 'approved',
            'D' => 'declined',
            'R' => 'rejected',
            'T' => 'timeout',
            'X' => 'failed',
            default => 'failed',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncPaymentRecords(Payment3dsSession $session, array $payload, string $status): void
    {
        $session->paymentAttempt?->update([
            'status' => $status === 'approved' ? 'approved' : $status,
            'processor_code' => $payload['BNRG_CODIGO_PROC'] ?? null,
            'processor_message' => $payload['BNRG_TEXTO'] ?? null,
            'processor_transaction_id' => $payload['BNRG_REFERENCIA'] ?? null,
            'raw_response' => $payload,
            'processed_at' => now(),
        ]);

        $session->paymentTransaction?->update([
            'reference' => $payload['BNRG_REFERENCIA'] ?? $session->paymentTransaction->reference,
            'auth_code' => $payload['BNRG_CODIGO_AUT'] ?? null,
            'status' => $status,
            'bnrg_codigo_proc' => $payload['BNRG_CODIGO_PROC'] ?? null,
            'bnrg_codigo_emisor' => $payload['BNRG_CODIGO_EMISOR'] ?? null,
            'bnrg_texto' => $payload['BNRG_TEXTO'] ?? null,
            'bnrg_codigo_rechazo' => $payload['BNRG_CODIGO_RECHAZO'] ?? null,
            'raw_response_headers' => $payload,
        ]);

        $session->paymentMethod?->update(['last_used_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createBusinessTransaction(Payment3dsSession $session, array $payload): Transaction
    {
        $existing = $this->findBusinessTransaction($session);

        if ($existing) {
            return $existing;
        }

        $paymentMethod = $session->paymentMethod;
        $amountCents = (int) round(((float) $session->amount) * 100);
        $reference = $session->reference ?? ('FM-3DS-' . $session->id);

        $context = is_array($session->checkout_context) ? $session->checkout_context : [];
        $details = [
            'description' => 'Pago 3DS procesado con Hey Banco',
            'customer_info' => [
                'customer_id' => $session->customer_id,
                'user_id' => $session->user_id,
            ],
            'token_info' => $paymentMethod ? [
                'payment_method_id' => $paymentMethod->id,
                'card_brand' => $paymentMethod->brand,
                'card_last_four' => $paymentMethod->last4,
                'alias' => $paymentMethod->alias,
            ] : null,
            'payment_details' => [
                'amount_cents' => $amountCents,
                'amount_mxn' => $session->amount,
                'reference' => $reference,
                'banregio_reference' => $payload['BNRG_REFERENCIA'] ?? null,
                'authorization_code' => $payload['BNRG_CODIGO_AUT'] ?? null,
                'folio' => $session->folio,
                'payment_attempt_id' => $session->payment_attempt_id,
                'payment_transaction_id' => $session->payment_transaction_id,
                'payment_3ds_session_id' => $session->id,
                'flow' => 'token_3ds_charge',
            ],
            'three_ds' => [
                'eci' => $payload['BNRG_3DS_ECI'] ?? null,
                'ucaf' => $payload['BNRG_3DS_UCAF'] ?? null,
                'xid' => $payload['BNRG_3DS_XID'] ?? null,
            ],
            'checkout_context' => $session->checkout_context,
            'processed_at' => now()->toISOString(),
        ];

        if (! empty($context['coupon_id'])) {
            $details['coupon_id'] = (int) $context['coupon_id'];
            $details['coupon_amount_cents'] = (int) ($context['discount_cents'] ?? 0);
            $details['original_total_cents'] = (int) ($context['total_cents'] ?? $amountCents);
            $details['amount_charged_cents'] = (int) ($context['amount_charged_cents'] ?? $amountCents);
        }

        return Transaction::create([
            'transaction_amount_cents' => $amountCents,
            'payment_method' => config('heybanco.provider_key'),
            'reference_id' => $reference,
            'gateway' => config('heybanco.provider_key'),
            'gateway_transaction_id' => $payload['BNRG_REFERENCIA'] ?? $session->folio,
            'gateway_status' => 'completed',
            'gateway_response' => $payload,
            'gateway_token' => $paymentMethod
                ? ('hb-token-ref:' . substr(md5((string) $paymentMethod->provider_token), 0, 20))
                : null,
            'gateway_processed_at' => now(),
            'gateway_authorization_code' => $payload['BNRG_CODIGO_AUT'] ?? null,
            'details' => $details,
        ]);
    }

    private function findBusinessTransaction(Payment3dsSession $session): ?Transaction
    {
        return Transaction::query()
            ->where('gateway', config('heybanco.provider_key'))
            ->where('details->payment_details->payment_3ds_session_id', $session->id)
            ->first();
    }

    private function findPurchaseId(Payment3dsSession $session): ?int
    {
        $transaction = $this->findBusinessTransaction($session);

        return $transaction?->laboratoryPurchases()->value('id');
    }
}
