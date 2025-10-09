<?php

namespace App\Actions\MedicalAttention;

use App\Models\CertificateAccount;
use App\Models\Customer;
use App\Models\FamilyAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class UpdateStatusAction
{
    private AuthorizationAction $authorizationAction;

    public function __construct(AuthorizationAction $authorizationAction)
    {
        $this->authorizationAction = $authorizationAction;
    }

    public function __invoke(
        Customer $customer,
        Carbon $startDate,
        Carbon $endDate,
        string $estatus = 'activo',
        ?string $producto = null,
        ?string $subProducto = null
    ): Response {
        $url = config('services.murguia.url') . 'asegurados/actualizacion';

        // Get fresh token for each request
        $authResponse = ($this->authorizationAction)();
        $token = $authResponse->json()['token'];

        $payload = [
            'noCredito' => $customer->medical_attention_identifier,
            'nombre' => $this->getCustomerName($customer),
            'campaña' => 'Famedic',
            'estatus' => $estatus,
            'producto' => $producto,
            'subProducto' => $subProducto,
            'inicioVigencia' => $startDate->format('d-m-Y'),
            'finVigencia' => $endDate->format('d-m-Y'),
        ];

        Log::info('Murguia update payload', [
            'customer_id' => $customer->id,
            'payload' => $payload,
        ]);

        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->put($url, $payload);
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
