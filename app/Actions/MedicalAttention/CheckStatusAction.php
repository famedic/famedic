<?php

namespace App\Actions\MedicalAttention;

use App\Models\Customer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class CheckStatusAction
{
    private AuthorizationAction $authorizationAction;

    public function __construct(AuthorizationAction $authorizationAction)
    {
        $this->authorizationAction = $authorizationAction;
    }

    public function __invoke(Customer|string $customerOrNoCredito): Response
    {
        $url = config('services.murguia.url') . 'asegurados/consultar-estatus';

        $authResponse = ($this->authorizationAction)();
        $token = $authResponse->json()['token'];

        $payload = [
            'noCredito' => $this->resolveNoCredito($customerOrNoCredito),
        ];

        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->post($url, $payload);
    }

    private function resolveNoCredito(Customer|string $customerOrNoCredito): string
    {
        if ($customerOrNoCredito instanceof Customer) {
            return (string) $customerOrNoCredito->medical_attention_identifier;
        }

        $noCredito = trim($customerOrNoCredito);
        if ($noCredito === '') {
            throw new InvalidArgumentException('noCredito vacío para consulta Murguía.');
        }

        return $noCredito;
    }
}
