<?php

namespace App\Actions\Laboratories;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetGDAResultsAction
{
    public function __invoke(string $orderId, ?array $notificationPayload = null): array
    {
        Log::info('🟢 GetGDAResultsAction iniciado:', [
            'order_id' => $orderId,
            'has_payload' => !empty($notificationPayload)
        ]);

        // Para entorno local, retornar datos de prueba
        /*if (app()->environment('local')) {
            Log::info('🟡 Usando mock results para entorno local');
            return $this->getMockResults($orderId, $notificationPayload);
        }*/

        // ===== OBTENER DATOS DEL PAYLOAD =====
        if (!$notificationPayload) {
            throw new Exception('Se requiere el payload de la notificación');
        }

        $marca = $notificationPayload['header']['marca'] ?? null;
        $convenio = $notificationPayload['requisition']['convenio'] ?? null;
        
        Log::info('📋 Datos extraídos del payload:', [
            'marca' => $marca,
            'convenio' => $convenio,
            'order_id_payload' => $notificationPayload['id'] ?? 'NO_ID'
        ]);

        if (!$marca || !$convenio) {
            throw new Exception('Faltan datos marca o convenio en el payload');
        }

        // ===== BUSCAR BRAND POR MARCA Y CONVENIO =====
        $brandConfig = $this->findBrandByMarcaAndConvenio($marca, $convenio);
        
        if (!$brandConfig) {
            throw new Exception("No se encontró configuración para marca={$marca}, convenio={$convenio}");
        }

        $url = config('services.gda.url') . '/consult';
        
        $payload = [
            "header" => [
                "lineanegocio" => "Notificasion-Resultados",
                "registro" => now()->isoFormat('YYYY-MM-DD\THH:mm:ss:SSS'),
                "marca" => (int) $marca,
                "token" => $brandConfig['token'],
            ],
            "resourceType" => "ServiceRequest",
            "id" => $orderId,
            "requisition" => [
                "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                "value" => "MD-" . $orderId,
                "convenio" => (int) $convenio,
            ],
            "status" => "completed",
            "intent" => "order",
            "priority" => "routine",
            "code" => [
                "coding" => $this->buildCoding($orderId),
            ],
            "orderdetail" => "Liberado",
            "quantityQuantity" => "1",
            "subject" => [
                "reference" => "Patient/4620606"
            ],
            "requester" => [
                "reference" => "Practitioner/5228",
                "display" => "A QUIEN CORRESPONDA"
            ],
        ];

        Log::info('📤 Enviando a GDA con datos del payload:', [
            'order_id' => $orderId,
            'marca' => $marca,
            'convenio' => $convenio,
            'brand_key' => $brandConfig['key'] ?? 'unknown'
        ]);

        $response = Http::timeout(60)->post($url, $payload);
        $responseData = $response->json();

        Log::info('📥 Respuesta GDA:', [
            'order_id' => $orderId,
            'http_status' => $response->status(),
            'has_pdf' => !empty($responseData['infogda_resultado_b64']),
            'gda_acuse' => $responseData['GDA_menssage']['acuse'] ?? 'NO_ACUSE'
        ]);

        if ($response->failed()) {
            throw new Exception('Error GDA: ' . ($responseData['GDA_menssage']['descripcion'] ?? $response->body()));
        }

        if (empty($responseData['infogda_resultado_b64'])) {
            throw new Exception('La respuesta no contiene resultados PDF');
        }

        Log::info('✅ Resultados obtenidos exitosamente');
        return $responseData;
    }

    /**
     * Buscar brand por marca y convenio
     */
    private function findBrandByMarcaAndConvenio(int $marca, int $convenio): ?array
    {
        $brands = config('services.gda.brands', []);
        
        foreach ($brands as $key => $config) {
            $brandId = (int) ($config['brand_id'] ?? 0);
            $agreementId = (int) ($config['brand_agreement_id'] ?? 0);
            
            // Buscar por marca O por convenio (por si hay coincidencias)
            if ($brandId === $marca || $agreementId === $convenio) {
                Log::info('✅ Brand encontrado:', [
                    'marca_buscada' => $marca,
                    'convenio_buscado' => $convenio,
                    'brand_key' => $key,
                    'brand_id_config' => $brandId,
                    'agreement_id_config' => $agreementId,
                    'match_by' => $brandId === $marca ? 'marca' : 'convenio'
                ]);
                
                return array_merge($config, ['key' => $key]);
            }
        }
        
        Log::warning('⚠️ No se encontró brand para:', [
            'marca' => $marca,
            'convenio' => $convenio,
            'brands_disponibles' => array_map(function($b, $k) {
                return $k . ' [id:' . ($b['brand_id'] ?? '?') . ', conv:' . ($b['brand_agreement_id'] ?? '?') . ']';
            }, $brands, array_keys($brands))
        ]);
        
        return null;
    }

    private function buildCoding(string $orderId): array
    {
        return [
            [
                "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                "code" => "561256",
                "display" => "BIOMETRIA HEMATICA COMPLETA",
                "infogda_status" => "completed",
                "infogda_cexamen" => "561256",
                "infogda_orden" => $orderId,
                "infogda_muestras" => [
                    [
                        "infogda_etiqueta" => $orderId . "02",
                        "infogda_contenedor" => "TUBO TAPON LILA CON EDTA",
                        "infogda_muestra" => "SANGRE TOTAL",
                        "infogda_kmuestrasucursal" => 40099781,
                        "infogda_contenedoracronim" => "TTL"
                    ]
                ],
                "infogda_preanaliticos" => []
            ]
        ];
    }

    private function getMockResults(string $orderId, ?array $payload = null): array
    {
        $marca = $payload['header']['marca'] ?? 5;
        $convenio = $payload['requisition']['convenio'] ?? 17479;
        
        Log::info('🟡 Mock results con:', [
            'order_id' => $orderId,
            'marca' => $marca,
            'convenio' => $convenio
        ]);

        return [
            "header" => [
                "lineanegocio" => "Notificasion-Resultados",
                "registro" => now()->format('M j, Y g:i:s A'),
                "marca" => $marca,
                "token" => "mock_token_" . time()
            ],
            "resourceType" => "ServiceRequest",
            "id" => $orderId,
            "requisition" => [
                "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                "value" => "MD-" . $orderId,
                "convenio" => $convenio
            ],
            "status" => "completed",
            "intent" => "order",
            "priority" => "routine",
            "code" => [
                "coding" => [
                    [
                        "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                        "code" => "561256",
                        "display" => "BIOMETRIA HEMATICA COMPLETA",
                        "infogda_status" => "completed",
                        "infogda_cexamen" => "561256",
                        "infogda_orden" => $orderId,
                        "infogda_muestras" => [
                            [
                                "infogda_etiqueta" => $orderId . "02",
                                "infogda_contenedor" => "TUBO TAPON LILA CON EDTA",
                                "infogda_muestra" => "SANGRE TOTAL",
                                "infogda_kmuestrasucursal" => 40099781,
                                "infogda_contenedoracronim" => "TTL"
                            ]
                        ],
                        "infogda_preanaliticos" => []
                    ]
                ]
            ],
            "orderdetail" => "Liberado",
            "quantityQuantity" => "1",
            "subject" => [
                "reference" => "Patient/4620606"
            ],
            "requester" => [
                "reference" => "Practitioner/5228",
                "display" => "A QUIEN CORRESPONDA"
            ],
            "GDA_menssage" => [
                "codeHttp" => 200,
                "mensaje" => "success",
                "descripcion" => "Resultado Obtenido Exitosamente",
                "acuse" => "mock-acuse-" . uniqid()
            ],
            "infogda_resultado_b64" => base64_encode('%PDF-1.4 Mock PDF for testing ' . $orderId)
        ];
    }
}