<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryTest;
use Exception;
use Illuminate\Support\Facades\Http;

class CreateGDAQuotationAction
{
    public function __invoke(array $cartItems, string $laboratoryBrand): array
    {
        // LOG CRÍTICO 5: El Action se está ejecutando
        logger('🎯 [ACTION] CREATE GDA QUOTATION ACTION INICIADO', [
            'items_count' => count($cartItems),
            'laboratory_brand' => $laboratoryBrand
        ]);

        try {
            $subtotal = collect($cartItems)->sum(fn($i) => $i['price'] * ($i['quantity'] ?? 1));
            $total = $subtotal;

            // Obtener configuración de la marca
            $brandConfig = $this->getBrandConfig($laboratoryBrand);
            logger('🎯 [ACTION] Configuración de marca obtenida:', [
                'brand_id' => $brandConfig['brand_id'],
                'agreement_id' => $brandConfig['brand_agreement_id']
            ]);

            // Procesar items (versión simplificada para debug)
            $coding = [];
            foreach ($cartItems as $index => $item) {
                logger("🎯 [ACTION] Procesando item {$index}:", [
                    'name' => $item['name'] ?? 'Sin nombre',
                    'gda_id' => $item['gda_id'] ?? 'Sin GDA ID',
                    'is_package' => $item['is_package'] ?? false
                ]);

                if ($item['is_package'] ?? false) {
                    logger("🎯 [ACTION] Item {$index} es PAQUETE - Procesando...");
                    // Para debug, tratar todos los paquetes como individuales temporalmente
                    $coding[] = $this->createCodingItem($item, $item['price']);
                } else {
                    logger("🎯 [ACTION] Item {$index} es INDIVIDUAL");
                    $coding[] = $this->createCodingItem($item, $item['price'] * ($item['quantity'] ?? 1));
                }
            }

            $payload = [
                "header" => [
                    "lineanegocio" => "Famedic",
                    "registro" => now()->format('Y-m-d\TH:i:s:v'),
                    "marca" => $brandConfig['brand_id'],
                    "token" => $brandConfig['token']
                ],
                "resourceType" => "ServiceRequestCotizacion",
                "id" => "",
                "requisition" => [
                    "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                    "value" => "42",
                    "convenio" => $brandConfig['brand_agreement_id'],
                    "marca" => $brandConfig['brand_id'],
                    "subtotal" => number_format($subtotal, 2, '.', ''),
                    "descuentopromocion" => "0.00",
                    "pagopaciente" => number_format($total, 2, '.', ''),
                    "total" => number_format($total, 2, '.', '')
                ],
                "status" => "active",
                "intent" => "order",
                "code" => [
                    "coding" => $coding
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

            logger('🎯 [ACTION] Payload construido, enviando a GDA...');

            // LOG CRÍTICO 6: Antes del request HTTP
            $gdaUrl = config('services.gda.url') . '/service-request-cotizacion';
            logger('🎯 [ACTION] URL GDA:', ['url' => $gdaUrl]);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($gdaUrl, $payload);

            // LOG CRÍTICO 7: Respuesta HTTP recibida
            logger('🎯 [ACTION] Respuesta HTTP recibida:', [
                'status_code' => $response->status(),
                'success' => $response->successful()
            ]);

            if ($response->failed()) {
                $errorBody = $response->body();
                logger('❌ [ACTION] ERROR GDA - Status: ' . $response->status(), [
                    'error_body' => $errorBody
                ]);
                throw new Exception('Error GDA - Status: ' . $response->status() . ' - Body: ' . $errorBody);
            }

            $jsonResponse = $response->json();
            logger('✅ [ACTION] RESPUESTA GDA EXITOSA:', [
                'tiene_acuse' => isset($jsonResponse['GDA_menssage']['acuse']),
                'acuse' => $jsonResponse['GDA_menssage']['acuse'] ?? 'NO_ACUSE'
            ]);

            return $jsonResponse;

        } catch (Exception $e) {
            logger('❌ [ACTION] EXCEPCIÓN EN ACTION:', [
                'error' => $e->getMessage(),
                'clase' => get_class($e),
                'linea' => $e->getLine(),
                'archivo' => $e->getFile()
            ]);
            throw $e;
        }
    }

    /**
     * Crear item de coding simplificado
     */
    private function createCodingItem(array $item, float $subtotal): array
    {
        return [
            "system" => "System",
            "code" => $item['gda_id'] ?? 'UNKNOWN',
            "display" => $item['name'] ?? 'Sin nombre',
            "subtotal" => number_format($subtotal, 2, '.', ''),
            "descuentopromocion" => "0.00",
            "pagopaciente" => number_format($subtotal, 2, '.', ''),
            "total" => number_format($subtotal, 2, '.', ''),
            "convenio" => "0",
            "quantity" => $item['quantity'] ?? 1
        ];
    }

    /**
     * Obtener configuración de la marca (sin cambios)
     */
    protected function getBrandConfig(string $laboratoryBrand): array
    {
        $brands = config('services.gda.brands');
        
        if (!isset($brands[$laboratoryBrand])) {
            throw new Exception("Configuración no encontrada para la marca: {$laboratoryBrand}");
        }

        $config = $brands[$laboratoryBrand];

        $required = ['brand_id', 'brand_agreement_id', 'token'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new Exception("Configuración faltante para {$laboratoryBrand}: {$key}");
            }
        }

        return $config;
    }
}