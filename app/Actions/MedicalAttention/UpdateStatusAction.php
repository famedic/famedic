<?php

namespace App\Actions\MedicalAttention;

use App\Models\CertificateAccount;
use App\Models\Customer;
use App\Models\FamilyAccount;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PUT /asegurados/actualizacion
 *
 * Por defecto envía solo lo documentado por Murguía: noCredito + estatus (activo|inactivo).
 * Las bajas y reactivaciones se reflejan así; no hay endpoint de borrado.
 *
 * {@see $extendedPayload} permite el cuerpo extendido (vigencia, producto, etc.) si algún flujo lo requiere.
 */
class UpdateStatusAction
{
    public function __construct(
        private AuthorizationAction $authorizationAction
    ) {}

    public function __invoke(
        Customer $customer,
        string $estatus,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?string $producto = null,
        ?string $subProducto = null,
        bool $extendedPayload = false,
    ): Response {
        $url = config('services.murguia.url') . 'asegurados/actualizacion';

        $authResponse = ($this->authorizationAction)();
        $token = $authResponse->json()['token'];

        if ($extendedPayload) {
            $start = $startDate ?? now();
            $end = $endDate ?? $start->copy()->addYear();
            $payload = [
                'noCredito' => $customer->medical_attention_identifier,
                'nombre' => $this->getCustomerName($customer),
                'campaña' => 'Famedic',
                'estatus' => $estatus,
                'producto' => $producto,
                'subProducto' => $subProducto !== null && $subProducto !== '' ? $subProducto : '-',
                'inicioVigencia' => $start->format('d-m-Y'),
                'finVigencia' => $end->format('d-m-Y'),
            ];
            Log::info('Murguia update payload (extended)', [
                'customer_id' => $customer->id,
                'payload' => $payload,
            ]);
        } else {
            $payload = [
                'noCredito' => (string) $customer->medical_attention_identifier,
                'estatus' => $estatus,
            ];
            Log::info('Murguia update payload (minimal)', [
                'customer_id' => $customer->id,
                'payload' => $payload,
            ]);
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->put($url, $payload);

        if (! $response->successful()) {
            Log::warning('Murguia actualizacion HTTP no exitosa', [
                'customer_id' => $customer->id,
                'minimal' => ! $extendedPayload,
                'http_status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);
        }

        return $response;
    }

    private function getCustomerName(Customer $customer): string
    {
        if ($customer->customerable_type === FamilyAccount::class && $customer->customerable) {
            return $customer->customerable->full_name;
        }

        if ($customer->customerable_type === CertificateAccount::class && $customer->customerable) {
            return $customer->customerable->name;
        }

        return $customer->user ? $customer->user->full_name : 'Unknown';
    }
}
