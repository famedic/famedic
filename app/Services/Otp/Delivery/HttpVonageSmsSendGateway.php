<?php

namespace App\Services\Otp\Delivery;

use App\Contracts\Otp\VonageSmsSendGateway;
use GuzzleHttp\Client as GuzzleClient;
use Vonage\Client;
use Vonage\Client\Credentials\Basic;
use Vonage\SMS\Collection;
use Vonage\SMS\Message\SMS;

final class HttpVonageSmsSendGateway implements VonageSmsSendGateway
{
    public function send(SMS $sms, string $apiKey, string $apiSecret): Collection
    {
        $httpClient = new GuzzleClient([
            'connect_timeout' => (int) config('otp.p0a.delivery.connect_timeout_seconds', 3),
            'timeout' => (int) config('otp.p0a.delivery.request_timeout_seconds', 8),
        ]);
        $client = new Client(new Basic($apiKey, $apiSecret), [], $httpClient);

        /** @var Collection $response */
        $response = $client->sms()->send($sms);

        return $response;
    }
}
