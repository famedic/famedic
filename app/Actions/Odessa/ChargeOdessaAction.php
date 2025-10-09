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

    public function __invoke(OdessaAfiliateAccount $odessaAfiliateAccount, int $centsAmount): Transaction
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

        $response = $this->sendChargeRequest($token, $url, $centsAmount, $transaction);

        logger($response->json());

        if ($response->failed() || $response->json()['response']['errorCode'] != 0) {
            throw new InvalidPaymentMethodException();
        }

        $referenceId = $response->json()['response']['transactionOds'];

        $transaction->update([
            'reference_id' => $referenceId,
        ]);

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
