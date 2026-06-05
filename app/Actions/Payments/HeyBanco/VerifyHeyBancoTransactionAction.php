<?php

namespace App\Actions\Payments\HeyBanco;

use App\Exceptions\HeyBancoPaymentException;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payments\HeyBanco\HeyBancoClient;
use App\Services\Payments\HeyBanco\HeyBancoResponse;
use Illuminate\Support\Facades\DB;

class VerifyHeyBancoTransactionAction
{
    public function __construct(
        private HeyBancoClient $client,
    ) {}

    public function byReference(
        User $user,
        string $reference,
        ?string $mediaId = null,
        ?PaymentTransaction $previousTransaction = null,
    ): PaymentTransaction {
        return DB::transaction(function () use ($user, $reference, $mediaId, $previousTransaction) {
            $response = $this->client->verifyByReference($reference, $mediaId);

            return $this->persistVerification($user, $response, $reference, $previousTransaction);
        });
    }

    public function byFolio(
        User $user,
        string $folio,
        ?string $mediaId = null,
        ?PaymentTransaction $previousTransaction = null,
    ): PaymentTransaction {
        return DB::transaction(function () use ($user, $folio, $mediaId, $previousTransaction) {
            $response = $this->client->verifyByFolio($folio, $mediaId);

            return $this->persistVerification($user, $response, $response->referencia(), $previousTransaction, $folio);
        });
    }

    private function persistVerification(
        User $user,
        HeyBancoResponse $response,
        ?string $previousReference,
        ?PaymentTransaction $previousTransaction = null,
        ?string $folio = null,
    ): PaymentTransaction {
        if (! $response->isApproved()) {
            throw new HeyBancoPaymentException(
                $response->texto() ?? 'La verificación de la transacción falló.',
                $response->codigoRechazo(),
                $response->texto(),
            );
        }

        $verification = PaymentTransaction::create([
            'user_id' => $user->id,
            'payment_method_id' => $previousTransaction?->payment_method_id,
            'provider' => config('heybanco.provider_key'),
            'flow' => 'verification',
            'folio' => $folio ?? $response->folio(),
            'reference' => $response->referencia(),
            'previous_reference' => $previousReference,
            'auth_code' => $response->codigoAut(),
            'amount' => $previousTransaction?->amount ?? 0,
            'currency' => config('heybanco.currency', 'MXN'),
            'mode' => config('heybanco.mode'),
            'status' => $response->isVerificationApproved() ? 'approved' : $response->statusLabel(),
            'bnrg_codigo_proc' => $response->codigoProc(),
            'bnrg_codigo_proc_trans' => $response->codigoProcTrans(),
            'bnrg_codigo_rechazo' => $response->codigoRechazo(),
            'bnrg_texto' => $response->texto(),
            'bnrg_estado_trans' => $response->estadoTrans(),
            'bnrg_tipo_trans' => $response->tipoTrans(),
            'raw_request' => $response->rawRequest,
            'raw_response_headers' => $response->normalizedHeaders,
        ]);

        if ($previousTransaction && $response->isVerificationApproved()) {
            $previousTransaction->update(['status' => 'approved']);
        }

        return $verification;
    }
}
