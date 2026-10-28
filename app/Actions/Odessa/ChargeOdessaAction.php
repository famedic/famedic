<?php

namespace App\Actions\Odessa;

use App\Exceptions\InvalidPaymentMethodException;
use App\Exceptions\OdessaInsufficientFundsException;
use App\Models\OdessaAfiliateAccount;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;

class ChargeOdessaAction
{
    private GetOdessaPrivateTokenAction $getOdessaPrivateTokenAction;
    private CheckBalanceAction $checkBalanceAction;

    public function __construct(
        GetOdessaPrivateTokenAction $getOdessaPrivateTokenAction,
        CheckBalanceAction $checkBalanceAction
    ) {
        $this->getOdessaPrivateTokenAction = $getOdessaPrivateTokenAction;
        $this->checkBalanceAction = $checkBalanceAction;
    }

    public function __invoke(OdessaAfiliateAccount $odessaAfiliateAccount, int $centsAmount, ?\App\Models\Cart $cart = null): Transaction
    {
        $token = ($this->getOdessaPrivateTokenAction)($odessaAfiliateAccount);

        $transaction = Transaction::create([
            'transaction_amount_cents' => $centsAmount,
            'payment_method' => 'odessa',
            'reference_id' => 'pending',
        ]);

        $url = config('services.odessa.url') . 'applyCharge';

        if (($this->checkBalanceAction)($token, $centsAmount) == false) {
            throw new OdessaInsufficientFundsException();
        }

        if ($cart) {
            $transaction->update(['payment_status' => 'processing', 'gateway' => 'odessa',
                'details' => ['cart_id' => $cart->id, 'laboratory_charge_started_at' => now()->toIso8601String()]]);
        }
        try {
            $response = $this->sendChargeRequest($token, $url, $centsAmount, $transaction);
        } catch (\Throwable $exception) {
            if ($cart) {
                throw new \App\Exceptions\LaboratoryPaymentInProgressException;
            }
            throw $exception;
        }

        $body = $response->json();
        $errorCode = data_get($body, 'response.errorCode');
        if ($response->successful() && $errorCode !== null && (string) $errorCode !== '0') {
            if ($cart) {
                $details = $transaction->details;
                unset($details['laboratory_charge_started_at']);
                $transaction->update(['payment_status' => 'declined', 'details' => $details]);
            }
            throw new InvalidPaymentMethodException();
        }
        $referenceId = data_get($body, 'response.transactionOds');
        if ($response->failed() || $errorCode === null || blank($referenceId)) {
            if ($cart) {
                throw new \App\Exceptions\LaboratoryPaymentInProgressException;
            }
            throw new InvalidPaymentMethodException();
        }
        try {
            $transaction->update([
                'reference_id' => $referenceId,
                'payment_status' => 'completed',
                'gateway' => 'odessa',
                'gateway_status' => 'completed',
                'gateway_transaction_id' => $referenceId,
                'gateway_processed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            if ($cart) {
                throw new \App\Exceptions\LaboratoryPaymentInProgressException;
            }
            throw $exception;
        }

        return $transaction;
    }

    public function sendChargeRequest($token, $url, $centsAmount, Transaction $transaction)
    {
        return Http::withOptions([
            'verify' => false,
        ])->withHeaders([
            'Authorization' => (string)('Bearer ' . $token),
            'Accept' => 'application/json'
        ])->post($url, [
            'request' => [
                'amount' => $centsAmount / 100,
                'transaction' => $transaction->id,
                "others" => '0|0|',
            ]
        ]);
    }
}
