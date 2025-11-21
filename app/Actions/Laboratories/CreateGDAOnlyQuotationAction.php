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
            // CORREGIR URL - eliminar doble //
            $baseUrl = rtrim(config('services.gda.url'), '/');
            $url = $baseUrl . '/service-request-cotizacion';
            
            logger('🔧 [ACTION] URL corregida:', ['url' => $url]);

            // Obtener configuraciones de la marca
            $brandConfig = config('services.gda.brands.' . $brand);
            
            if (!$brandConfig) {
                throw new Exception("Configuración no encontrada para la marca: {$brand}");
            }

            // VERIFICAR CREDENCIALES
            if (empty($brandConfig['token'])) {
                throw new Exception("Token no configurado para la marca: {$brand}");
            }

            logger('🔑 [ACTION] Usando configuración:', [
                'brand_id' => $brandConfig['brand_id'],
                'agreement_id' => $brandConfig['brand_agreement_id'],
                'token_length' => strlen($brandConfig['token'])
            ]);

            // Construir payload CORREGIDO según el Excel
            $payload = [
                "header" => [
                    "lineanegocio" => "Famedic",
                    "registro" => Carbon::now()->format('Y-m-d\TH:i:s:v'),
                    "marca" => (int) $brandConfig['brand_id'], // Asegurar que sea int
                    "token" => $brandConfig['token'],
                ],
                "resourceType" => "ServiceRequestCotizacion", // ← SEGÚN EXCEL
                "id" => "",
                "requisition" => [
                    "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                    "value" => "42", // ← VALOR FIJO según Excel
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
                    "coding" => $this->buildCoding($laboratoryCartItems),
                ],
                "orderdetail" => "",
                "quantityQuantity" => (string) $laboratoryCartItems->count(),
                "subject" => [
                    "reference" => "Patient/4620606", // VALOR FIJO según testing
                ],
                "requester" => [
                    "reference" => "Practitioner/5228", // VALOR FIJO según testing
                    "display" => "A QUIEN CORRESPONDA"
                ],
                "GDA_menssage" => [ // ← AGREGAR según Excel
                    "codeHttp" => "",
                    "mensaje" => "error|success", 
                    "descripcion" => "",
                    "acuse" => ""
                ]
            ];

            logger('📤 [ACTION] Enviando payload a GDA:', [
                'url' => $url,
                'payload_keys' => array_keys($payload)
            ]);

            // LOG del payload completo (sin token por seguridad)
            $payloadLog = $payload;
            $payloadLog['header']['token'] = '***HIDDEN***';
            logger('📋 [ACTION] Payload completo:', $payloadLog);

            // Llamada a GDA con más detalles de debug
            $response = Http::timeout(60)
                          ->withHeaders([
                              'Content-Type' => 'application/json',
                              'Accept' => 'application/json'
                          ])
                          ->post($url, $payload);

            $responseData = $response->json();
            $responseStatus = $response->status();
            
            logger('📥 [ACTION] Respuesta GDA:', [
                'status' => $responseStatus,
                'headers' => $response->headers(),
                'body' => $responseData
            ]);

            if ($response->failed()) {
                $errorMessage = "Error GDA - Status: {$responseStatus}";
                
                // Agregar más detalles del error si están disponibles
                if (isset($responseData['error'])) {
                    $errorMessage .= " - Error: " . $responseData['error'];
                }
                if (isset($responseData['message'])) {
                    $errorMessage .= " - Message: " . $responseData['message'];
                }
                
                throw new Exception($errorMessage);
            }

            // Validar respuesta
            if (empty($responseData)) {
                throw new Exception('Respuesta GDA vacía');
            }

            logger('✅ [ACTION] Llamada a GDA exitosa');
            return $responseData;

        } catch (\Throwable $th) {
            logger('❌ [ACTION] Error en CreateGDAOnlyQuotationAction:', [
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine()
            ]);
            throw $th;
        }
    }

    /**
     * Calcular subtotal según formato GDA
     */
    private function calculateSubtotal($laboratoryCartItems): string
    {
        $total = 0;
        foreach ($laboratoryCartItems as $item) {
            $total += $item->laboratoryTest->famedic_price_cents / 100; // Convertir a pesos
        }
        
        return number_format($total, 2, '.', '');
    }

    /**
     * Calcular total según formato GDA
     */
    private function calculateTotal($laboratoryCartItems): string
    {
        return $this->calculateSubtotal($laboratoryCartItems); // Por ahora sin descuentos
    }

    /**
     * Construir coding según estructura del Excel
     */
    private function buildCoding($laboratoryCartItems): array
    {
        $coding = [];

        foreach ($laboratoryCartItems as $item) {
            $price = $item->laboratoryTest->famedic_price_cents / 100; // Convertir a pesos
            
            $coding[] = [
                "system" => "System", // ← SEGÚN EXCEL
                "code" => $item->laboratoryTest->gda_id,
                "display" => $item->laboratoryTest->name,
                "subtotal" => number_format($price, 2, '.', ''),
                "descuentopromocion" => "0.00",
                "pagopaciente" => number_format($price, 2, '.', ''),
                "total" => number_format($price, 2, '.', ''),
                "convenio" => "0", // ← SEGÚN EXCEL
                "quantity" => "1" // ← AGREGAR quantity
            ];
        }

        logger('🔢 [ACTION] Coding construido:', [
            'items_count' => count($coding),
            'first_item' => $coding[0] ?? 'No items'
        ]);

        return $coding;
    }
}