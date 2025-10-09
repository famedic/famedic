<?php

namespace App\Actions\MedicalAttention;

use App\Exceptions\MurguiaConflictException;
use App\Models\CertificateAccount;
use App\Models\Customer;
use App\Models\FamilyAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RegisterAction
{
    private AuthorizationAction $authorizationAction;

    public function __construct(AuthorizationAction $authorizationAction)
    {
        $this->authorizationAction = $authorizationAction;
    }

    public function __invoke(
        Customer $customer,
        ?Carbon $membershipStartDate = null,
        ?Carbon $membershipEndDate = null,
        ?string $producto = null,
        ?string $subProducto = null
    ): Response {
        $url = config('services.murguia.url') . 'asegurados/registro';

        // Get fresh token for each request
        $authResponse = ($this->authorizationAction)();
        $token = $authResponse->json()['token'];

        $start = $membershipStartDate ?: now();
        $end = $membershipEndDate ?: now()->addYear();

        $payload = [
            'noCredito' => (string) $customer->medical_attention_identifier,
            'nombre' => $this->getCustomerName($customer),
            'campaña' => 'Famedic',
            'producto' => $producto,
            'subProducto' => $subProducto ?: '-',
            'inicioVigencia' => $start->format('d-m-Y'),
            'finVigencia' => $end->format('d-m-Y'),
        ];

        Log::info('Murguia register payload', [
            'customer_id' => $customer->id,
            'payload' => $payload,
        ]);


        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->post($url, $payload);

        // Handle conflict errors (duplicate user, etc.)
        if ($response->status() === 409) {
            Log::warning('Murguia register conflict', [
                'customer_id' => $customer->id,
                'identifier' => $customer->medical_attention_identifier,
                'error' => $response->json()['error'] ?? 'Unknown conflict',
            ]);
            throw new MurguiaConflictException('Customer already exists in Murguia: ' . $customer->medical_attention_identifier);
        }

        return $response;
    }

    private function getCustomerName(Customer $customer): string
    {
        // For family accounts, use the family member's name
        if ($customer->customerable_type === FamilyAccount::class && $customer->customerable) {
            return $customer->customerable->full_name;
        }

        // For certificate accounts, use the certificate holder's name
        if ($customer->customerable_type === CertificateAccount::class && $customer->customerable) {
            return $customer->customerable->name;
        }

        // For regular accounts, use the user's name
        return $customer->user ? $customer->user->full_name : 'Unknown';
    }
}
