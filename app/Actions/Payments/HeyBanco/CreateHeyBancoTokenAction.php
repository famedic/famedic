<?php

namespace App\Actions\Payments\HeyBanco;

use App\Exceptions\HeyBancoPaymentException;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\HeyBanco\HeyBancoClient;
use App\Services\Payments\HeyBanco\HeyBancoResponse;
use Illuminate\Support\Facades\DB;

class CreateHeyBancoTokenAction
{
    public function __construct(
        private HeyBancoClient $client,
    ) {}

    /**
     * @param  array{card_number: string, exp_month: string, exp_year: string, cvv: string, card_holder?: string, alias?: string}  $cardData
     */
    public function __invoke(User $user, array $cardData): PaymentMethod
    {
        $cardNumber = preg_replace('/\D/', '', $cardData['card_number']) ?? '';
        $last4 = substr($cardNumber, -4);
        $brand = $this->detectBrand($cardNumber);

        return DB::transaction(function () use ($user, $cardData, $last4, $brand) {
            $response = $this->client->createToken(
                $cardData,
                reference: 'user-' . $user->id
            );

            $paymentTransaction = $this->persistTransaction(
                user: $user,
                response: $response,
                flow: 'token_creation',
                amount: 0,
                folio: $cardData['folio'] ?? ($response->folio() ?? null),
            );

            if (! $response->isApproved() || empty($response->token())) {
                throw new HeyBancoPaymentException(
                    $response->texto() ?? 'No se pudo tokenizar la tarjeta con Hey Banco.',
                    $response->codigoRechazo(),
                    $response->texto(),
                );
            }

            $paymentMethod = PaymentMethod::create([
                'user_id' => $user->id,
                'provider' => config('heybanco.provider_key'),
                'provider_token' => $response->token(),
                'brand' => $brand,
                'last4' => $last4,
                'exp_month' => str_pad($cardData['exp_month'], 2, '0', STR_PAD_LEFT),
                'exp_year' => strlen($cardData['exp_year']) === 2
                    ? '20' . $cardData['exp_year']
                    : $cardData['exp_year'],
                'affiliation_id' => config('heybanco.token_affiliation'),
                'media_id' => config('heybanco.token_media_id'),
                'status' => 'active',
                'alias' => $cardData['alias'] ?? ($brand . '-' . $last4),
                'card_holder' => $cardData['card_holder'] ?? null,
                'created_from_transaction_id' => $paymentTransaction->id,
            ]);

            $paymentTransaction->update([
                'payment_method_id' => $paymentMethod->id,
                'status' => 'approved',
            ]);

            return $paymentMethod;
        });
    }

    private function persistTransaction(
        User $user,
        HeyBancoResponse $response,
        string $flow,
        float $amount,
        ?string $folio = null,
    ): PaymentTransaction {
        return PaymentTransaction::create([
            'user_id' => $user->id,
            'provider' => config('heybanco.provider_key'),
            'flow' => $flow,
            'folio' => $folio ?? $response->folio(),
            'reference' => $response->referencia(),
            'auth_code' => $response->codigoAut(),
            'amount' => $amount,
            'currency' => config('heybanco.currency', 'MXN'),
            'mode' => config('heybanco.mode'),
            'status' => $response->statusLabel(),
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

    private function detectBrand(string $cardNumber): string
    {
        if (preg_match('/^4/', $cardNumber)) {
            return 'visa';
        }

        if (preg_match('/^5[1-5]/', $cardNumber)) {
            return 'mastercard';
        }

        if (preg_match('/^3[47]/', $cardNumber)) {
            return 'amex';
        }

        return 'unknown';
    }
}
