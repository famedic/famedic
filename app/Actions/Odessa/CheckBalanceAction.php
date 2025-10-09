<?php

namespace App\Actions\Odessa;

use App\Exceptions\InvalidPaymentMethodException;
use App\Exceptions\OdessaInsufficientFundsException;
use Illuminate\Support\Facades\Http;

class CheckBalanceAction
{
    public function __invoke(string $token, int $centsAmount): bool
    {
        $url = config('services.odessa.url') . 'checkBalance/' . (float)($centsAmount / 100);

        $response = $this->sendRequest($token, $url);

        logger($response->json());

        if ($response->failed()) {
            throw new InvalidPaymentMethodException(json_encode($response->json()));
        }

        if ($response->json()['response']['errorCode'] === 6) {
            throw new OdessaInsufficientFundsException($response->json()['response']['message']);
        }

        if ($response->json()['response']['errorCode'] != 0) {
            throw new InvalidPaymentMethodException(json_encode($response->json()));
        }

        return (bool)$response->json()['response']['isAvailable'];
    }

    public function sendRequest($token, $url)
    {
        return Http::withOptions([
            'verify' => false,
        ])->withHeaders([
            'Authorization' => (string)('Bearer ' . $token),
            'Accept' => 'application/json'
        ])->get($url);
    }
}
