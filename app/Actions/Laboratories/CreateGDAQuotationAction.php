<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryTest;
use Exception;
use Illuminate\Support\Facades\Http;

class CreateGDAQuotationAction
{
    public function __invoke(array $cartItems): array
    {
        $labTests = LaboratoryTest::findMany(
            collect($cartItems)->pluck('test_id')
        )->keyBy('id');

        $subtotal = collect($cartItems)->sum(fn($i) => $i['price'] * ($i['quantity'] ?? 1));
        $total = $subtotal;

        // Construir array de coding con todos los items
        $coding = [];
        foreach ($cartItems as $item) {
            $labTest = $labTests[$item['test_id']];
            $itemSubtotal = $item['price'] * ($item['quantity'] ?? 1);

            $coding[] = [
                "system" => "System",
                "code" => $labTest->gda_id,
                "display" => $labTest->name,
                "subtotal" => number_format($itemSubtotal, 2, '.', ''),
                "descuentopromocion" => "0.00",
                "pagopaciente" => number_format($itemSubtotal, 2, '.', ''),
                "total" => number_format($itemSubtotal, 2, '.', ''),
                "convenio" => "0",
                "quantity" => $item['quantity'] ?? 1
            ];
        }

        $payload = [
            "header" => [
                "lineanegocio" => "Famedic",
                "registro" => now()->format('Y-m-d\TH:i:s:v'),
                "marca" => "1",
                "token" => "wDC+haCjTkDPLViLdKbC5hkmX1F4e9C+TwCizK3sRUCH4LFM0kj24Y05+bZ3k5Dm"
            ],
            "resourceType" => "ServiceRequestCotizacion",
            "id" => "",
            "requisition" => [
                "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                "value" => "42",
                "convenio" => "17479",
                "marca" => "1",
                "subtotal" => number_format($subtotal, 2, '.', ''),
                "descuentopromocion" => "0.00",
                "pagopaciente" => number_format($total, 2, '.', ''),
                "total" => number_format($total, 2, '.', '')
            ],
            "status" => "active",
            "intent" => "order",
            "code" => [
                "coding" => $coding // ← Ahora incluye todos los items
            ],
            "orderdetail" => "",
            "quantityQuantity" => (string) count($cartItems),
            "subject" => [
                "reference" => "Patient/4620606"
            ],
            "requester" => [
                "reference" => "Practitioner/5228",
                "display" => "A QUIEN CORRESPONDA"
            ]
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(config('services.gda.url') . '/service-request-cotizacion', $payload);

        if ($response->failed()) {
            throw new Exception('Error GDA: ' . $response->body());
        }

        return $response->json();
    }
}