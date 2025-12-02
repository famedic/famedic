<?php

namespace App\Actions\Laboratories;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetGDAResultsAction
{
    public function __invoke(string $orderId, string $brand): array
    {
        // Para entorno local, retornar datos de prueba
        if (app()->environment('local')) {
            return $this->getMockResults($orderId);
        }

        $url = config('services.gda.url') . '/consult';
        
        $payload = [
            "header" => [
                "lineanegocio" => "Notificasion-Resultados",
                "registro" => now()->isoFormat('YYYY-MM-DD\THH:mm:ss:SSS'),
                "marca" => (int) config('services.gda.brands.' . $brand . '.brand_id'),
                "token" => config('services.gda.brands.' . $brand . '.token'),
            ],
            "resourceType" => "ServiceRequest",
            "id" => $orderId,
            "requisition" => [
                "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                "value" => "MD-" . $orderId,
                "convenio" => (int) config('services.gda.brands.' . $brand . '.brand_agreement_id'),
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

        Log::info('Solicitando resultados a GDA:', [
            'order_id' => $orderId,
            'brand' => $brand,
            'url' => $url
        ]);

        $response = Http::timeout(60)->post($url, $payload);

        $responseData = $response->json();

        Log::info('Respuesta de GDA para resultados:', [
            'order_id' => $orderId,
            'http_status' => $response->status(),
            'has_pdf' => !empty($responseData['infogda_resultado_b64'])
        ]);

        if ($response->failed()) {
            throw new Exception('Error al obtener resultados del laboratorio: ' . $response->body());
        }

        // Verificar si la respuesta contiene resultados
        if (empty($responseData['infogda_resultado_b64'])) {
            throw new Exception('La respuesta no contiene resultados PDF');
        }

        return $responseData;
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

    private function getMockResults(string $orderId): array
    {
        // PDF base64 de ejemplo (un PDF vacío simple)
        $mockPdfBase64 = "JVBERi0xLjQKMSAwIG9iago8PAovVGl0bGUgKP7/...";

        return [
            "header" => [
                "lineanegocio" => "Notificasion-Resultados",
                "registro" => now()->format('M j, Y g:i:s A'),
                "marca" => 1,
                "token" => "mock_token_" . time()
            ],
            "resourceType" => "ServiceRequest",
            "id" => $orderId,
            "requisition" => [
                "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                "value" => "MD-" . $orderId,
                "convenio" => 17479
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
                "descripcion" => "Resultado Obtenido Exitosamente"
            ],
            "infogda_resultado_b64" => $mockPdfBase64
        ];
    }
}