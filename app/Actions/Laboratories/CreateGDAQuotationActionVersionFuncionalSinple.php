<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryTest;
use Exception;
use Illuminate\Support\Facades\Http;

class CreateGDAQuotationActionVersionFuncionalSinple
{
    public function __invoke(array $cartItems, string $laboratoryBrand): array
    {
        logger('🎯 [ACTION] CREATE GDA QUOTATION ACTION INICIADO', [
            'items_count' => count($cartItems),
            'laboratory_brand' => $laboratoryBrand,
            'items_detalle' => $cartItems // Log detallado de todos los items
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

            // Procesar items - VERSIÓN CORREGIDA PARA PAQUETES
            $coding = [];
            $totalQuantity = 0;

            foreach ($cartItems as $index => $item) {
                logger("🎯 [ACTION] Procesando item {$index}:", [
                    'name' => $item['name'] ?? 'Sin nombre',
                    'gda_id' => $item['gda_id'] ?? 'Sin GDA ID',
                    'is_package' => $item['is_package'] ?? false,
                    'feature_list' => $item['feature_list'] ?? [],
                    'price' => $item['price'] ?? 0,
                    'quantity' => $item['quantity'] ?? 1
                ]);

                if ($item['is_package'] ?? false) {
                    logger("🎯 [ACTION] Item {$index} es PAQUETE - Procesando como individual");
                    // PARA PAQUETES: Enviar como estudio individual con su propio GDA ID
                    $coding[] = $this->createCodingItem($item, $item['price'] * ($item['quantity'] ?? 1));
                    $totalQuantity += ($item['quantity'] ?? 1);
                    
                } else {
                    logger("🎯 [ACTION] Item {$index} es INDIVIDUAL");
                    $coding[] = $this->createCodingItem($item, $item['price'] * ($item['quantity'] ?? 1));
                    $totalQuantity += ($item['quantity'] ?? 1);
                }
            }

            // SI NECESITAS DESGLOSAR PAQUETES EN ESTUDIOS INDIVIDUALES, USA ESTA VERSIÓN:
            /*
            foreach ($cartItems as $index => $item) {
                if ($item['is_package'] ?? false) {
                    logger("🎯 [ACTION] Item {$index} es PAQUETE - Desglosando en estudios individuales");
                    
                    // Obtener los estudios individuales del paquete
                    $packageStudies = $this->getPackageStudies($item);
                    
                    foreach ($packageStudies as $study) {
                        $coding[] = $this->createCodingItem($study, $study['price']);
                        $totalQuantity += ($study['quantity'] ?? 1);
                    }
                } else {
                    logger("🎯 [ACTION] Item {$index} es INDIVIDUAL");
                    $coding[] = $this->createCodingItem($item, $item['price'] * ($item['quantity'] ?? 1));
                    $totalQuantity += ($item['quantity'] ?? 1);
                }
            }
            */

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
                "quantityQuantity" => (string) $totalQuantity,
                "subject" => [
                    "reference" => "Patient/4620606"
                ],
                "requester" => [
                    "reference" => "Practitioner/5228",
                    "display" => "A QUIEN CORRESPONDA"
                ]
            ];

            logger('🎯 [ACTION] Payload construido:', [
                'total_items_coding' => count($coding),
                'total_quantity' => $totalQuantity,
                'subtotal' => $subtotal
            ]);

            // LOG CRÍTICO: Antes del request HTTP
            $gdaUrl = config('services.gda.url') . '/service-request-cotizacion';
            logger('🎯 [ACTION] URL GDA:', ['url' => $gdaUrl]);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($gdaUrl, $payload);

            // LOG CRÍTICO: Respuesta HTTP recibida
            logger('🎯 [ACTION] Respuesta HTTP recibida:', [
                'status_code' => $response->status(),
                'success' => $response->successful()
            ]);

            if ($response->failed()) {
                $errorBody = $response->body();
                logger('❌ [ACTION] ERROR GDA - Status: ' . $response->status(), [
                    'error_body' => $errorBody,
                    'payload_enviado' => $payload // Log del payload que causó el error
                ]);
                throw new Exception('Error GDA - Status: ' . $response->status() . ' - Body: ' . $errorBody);
            }

            $jsonResponse = $response->json();
            logger('✅ [ACTION] RESPUESTA GDA EXITOSA:', [
                'tiene_acuse' => isset($jsonResponse['GDA_menssage']['acuse']),
                'acuse' => $jsonResponse['GDA_menssage']['acuse'] ?? 'NO_ACUSE',
                'respuesta_completa' => $jsonResponse
            ]);

            return $jsonResponse;

        } catch (Exception $e) {
            logger('❌ [ACTION] EXCEPCIÓN EN ACTION:', [
                'error' => $e->getMessage(),
                'clase' => get_class($e),
                'linea' => $e->getLine(),
                'archivo' => $e->getFile(),
                'cart_items' => $cartItems // Log de los items que causaron el error
            ]);
            throw $e;
        }
    }

    /**
     * Crear item de coding - VERSIÓN MEJORADA
     */
    private function createCodingItem(array $item, float $subtotal): array
    {
        $codingItem = [
            "system" => "System",
            "code" => $item['gda_id'] ?? 'UNKNOWN',
            "display" => $item['name'] ?? 'Sin nombre',
            "subtotal" => number_format($subtotal, 2, '.', ''),
            "descuentopromocion" => "0.00",
            "pagopaciente" => number_format($subtotal, 2, '.', ''),
            "total" => number_format($subtotal, 2, '.', ''),
            "convenio" => "0",
            "quantity" => (string) ($item['quantity'] ?? 1)
        ];

        logger("🎯 [ACTION] Coding item creado:", $codingItem);
        return $codingItem;
    }

    /**
     * Método auxiliar para desglosar paquetes en estudios individuales
     * (OPCIONAL - usar si GDA requiere estudios individuales en lugar del paquete completo)
     */
    private function getPackageStudies(array $packageItem): array
    {
        // Esta lógica depende de cómo tengas mapeados los estudios individuales
        // Por ahora retornamos el paquete como un solo item
        // Si necesitas desglosar, aquí iría la lógica para mapear feature_list a estudios GDA
        
        logger("🎯 [ACTION] Desglosando paquete:", [
            'package_name' => $packageItem['name'],
            'feature_list' => $packageItem['feature_list'] ?? []
        ]);

        // EJEMPLO: Si tuvieras mapeo de estudios individuales
        // $packageStudies = [];
        // foreach ($packageItem['feature_list'] as $feature) {
        //     $studyGdaId = $this->mapFeatureToGdaId($feature);
        //     if ($studyGdaId) {
        //         $packageStudies[] = [
        //             'gda_id' => $studyGdaId,
        //             'name' => $feature,
        //             'price' => 0, // O calcular precio proporcional
        //             'quantity' => 1
        //         ];
        //     }
        // }
        
        return [$packageItem]; // Por defecto, retornar el paquete como item único
    }

    /**
     * Obtener configuración de la marca
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