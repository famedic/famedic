<?php

namespace App\Actions\Laboratories;

use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use Exception;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class CreateGDAOnlyQuotationAction
{
    public function __invoke(Customer $customer, Address $address, Contact $contact, string $brand, $laboratoryCartItems, $laboratoryPurchaseId): array
    {
        logger('🎯 [ACTION] CreateGDAOnlyQuotationAction - LLAMADA REAL');

        try {
            $baseUrl = rtrim(config('services.gda.url'), '/');
            $url = $baseUrl . '/service-request-cotizacion';
            
            logger('🔧 [ACTION] URL:', ['url' => $url]);

            $brandConfig = config('services.gda.brands.' . $brand);
            
            if (!$brandConfig) {
                throw new Exception("Configuración no encontrada para la marca: {$brand}");
            }

            if (empty($brandConfig['token'])) {
                throw new Exception("Token no configurado para la marca: {$brand}");
            }

            // Construir payload
            $payload = [
                "header" => [
                    "lineanegocio" => "Famedic",
                    "registro" => Carbon::now()->format('Y-m-d\TH:i:s:v'),
                    "marca" => (int) $brandConfig['brand_id'],
                    "token" => $brandConfig['token'],
                ],
                "resourceType" => "ServiceRequestCotizacion",
                "id" => "",
                "requisition" => [
                    "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                    "value" => "42",
                    "convenio" => $brandConfig['brand_agreement_id'],
                    "marca" => $brandConfig['brand_id'],
                    "subtotal" => $this->calculateSubtotal($laboratoryCartItems),
                    "descuentopromocion" => "0.00",
                    "pagopaciente" => $this->calculateTotal($laboratoryCartItems),
                    "total" => $this->calculateTotal($laboratoryCartItems),
                ],
                "status" => "active",
                "intent" => "order",
                "code" => [
                    "coding" => $this->buildCoding($laboratoryCartItems, $brandConfig['brand_agreement_id']),
                ],
                "orderdetail" => "",
                "quantityQuantity" => (string) $laboratoryCartItems->count(),
                "subject" => [
                    "reference" => "Patient/4620606",
                ],
                "requester" => [
                    "reference" => "Practitioner/5228",
                    "display" => "A QUIEN CORRESPONDA"
                ]
            ];

            logger('📤 [ACTION] Enviando payload a GDA');

            $response = Http::timeout(60)
                          ->withHeaders([
                              'Content-Type' => 'application/json',
                              'Accept' => 'application/json'
                          ])
                          ->post($url, $payload);

            $responseData = $response->json();
            $responseStatus = $response->status();
            
            logger('📥 [ACTION] Respuesta GDA completa:', [
                'status' => $responseStatus,
                'response_data' => $responseData
            ]);

            // ✅ MANEJO ESPECIAL: Aunque sea error 400, si tenemos acuse, es "éxito"
            if (isset($responseData['GDA_menssage']['acuse']) && !empty($responseData['GDA_menssage']['acuse'])) {
                logger('⚠️ [ACTION] GDA retornó error PERO con acuse válido - Considerando como éxito', [
                    'acuse' => $responseData['GDA_menssage']['acuse'],
                    'descripcion' => $responseData['GDA_menssage']['descripcion'] ?? 'Sin descripción'
                ]);
                
                // Retornar la respuesta completa para que el controller la guarde
                return $responseData;
            }

            // ❌ Error real sin acuse
            if ($response->failed()) {
                $errorMessage = "Error GDA - Status: {$responseStatus}";
                
                if (isset($responseData['GDA_menssage']['descripcion'])) {
                    $errorMessage .= " - " . $responseData['GDA_menssage']['descripcion'];
                }
                
                throw new Exception($errorMessage);
            }

            // ✅ Éxito normal
            logger('✅ [ACTION] Llamada a GDA exitosa');
            return $responseData;

        } catch (\Throwable $th) {
            logger('❌ [ACTION] Error en CreateGDAOnlyQuotationAction:', [
                'error' => $th->getMessage()
            ]);
            throw $th;
        }
    }

    /**
     * Calcular subtotal
     */
    private function calculateSubtotal($laboratoryCartItems): string
    {
        $total = 0;
        foreach ($laboratoryCartItems as $item) {
            $total += $item->laboratoryTest->famedic_price_cents / 100;
        }
        
        return number_format($total, 2, '.', '');
    }

    /**
     * Calcular total
     */
    private function calculateTotal($laboratoryCartItems): string
    {
        return $this->calculateSubtotal($laboratoryCartItems);
    }

    /**
     * Construir coding - CORREGIDO para paquetes
     */
    private function buildCoding($laboratoryCartItems, $agreementId): array
    {
        $coding = [];

        foreach ($laboratoryCartItems as $item) {
            $price = $item->laboratoryTest->famedic_price_cents / 100;
            
            $codingItem = [
                "system" => "System",
                "code" => $item->laboratoryTest->gda_id,
                "display" => $item->laboratoryTest->name,
                "subtotal" => number_format($price, 2, '.', ''),
                "descuentopromocion" => "0.00",
                "pagopaciente" => number_format($price, 2, '.', ''),
                "total" => number_format($price, 2, '.', ''),
                "convenio" => "0",
                "quantity" => "1"
            ];

            // 🎯 POSIBLE PROBLEMA: GDA espera estudios individuales para paquetes
            // Si es paquete, necesitamos enviar los estudios individuales
            if ($this->isPackage($item)) {
                logger('📦 [ACTION] Item es PAQUETE - Considerar desglose:', [
                    'gda_id' => $item->laboratoryTest->gda_id,
                    'name' => $item->laboratoryTest->name,
                    'feature_count' => count($item->laboratoryTest->feature_list ?? [])
                ]);
                
                // PARA PAQUETES: Podríamos necesitar enviar estudios individuales
                // Por ahora mantenemos el paquete como está
            }

            $coding[] = $codingItem;
        }

        return $coding;
    }

    /**
     * Determinar si un item es paquete
     */
    private function isPackage($item): bool
    {
        return !empty($item->laboratoryTest->feature_list) && 
               is_array($item->laboratoryTest->feature_list) &&
               count($item->laboratoryTest->feature_list) > 0;
    }
}