<?php

namespace App\Actions\Odessa;

use App\Models\OdessaAfiliateAccount;
use Exception;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GetOdessaPrivateTokenAction
{
    public function __invoke(OdessaAfiliateAccount $odessaAfiliateAccount): string
    {
        $now = time();

        $token = JWT::encode([
            'idOdessa' => (int)$odessaAfiliateAccount->odessa_identifier,
            'idUser' => (int)$odessaAfiliateAccount->id,
            'iss' => 'FAMEDIC',
            'aud' => 'ODESSA',
            'iat' => $now,
            'exp' => $now + (60 * 60)
        ], base64_decode(env('FAMEDIC_PUBLIC_KEY')), 'RS512');

        $url = config('services.odessa.url') . 'getToken/';

        try {
            $response = $this->sendRequest($url, $token, 0);
        } catch (ConnectionException $th) {
            $response = $this->sendRequest($url, $token, 0);
        }

        if ($response->failed() || $response->json()['response']['errorCode'] != 0) {
            throw new Exception(json_encode($response->json()));
        }

        return $response->json()['response']['token'];
    }

    public function sendRequest(string $url, string $token, int $timeout = 30): Response
    {
        return Http::timeout($timeout)->withOptions([
            'verify' => false,
        ])->withHeaders([
            'Authorization' => (string)('Bearer ' . $token),
            'Accept' => 'application/json'
        ])->get($url);
    }
}
