<?php

namespace App\Actions\MedicalAttention;

use App\Models\Customer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CheckStatusAction
{
    private AuthorizationAction $authorizationAction;

    public function __construct(AuthorizationAction $authorizationAction)
    {
        $this->authorizationAction = $authorizationAction;
    }

    public function __invoke(Customer $customer): Response
    {
        $url = config('services.murguia.url') . 'asegurados/consultar-estatus';

        $authResponse = ($this->authorizationAction)();
        $token = $authResponse->json()['token'];

        $payload = [
            'noCredito' => (string) $customer->medical_attention_identifier,
        ];

        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->post($url, $payload);
    }
}
