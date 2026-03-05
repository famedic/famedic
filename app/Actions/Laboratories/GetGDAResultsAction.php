<?php

namespace App\Actions\Laboratories;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetGDAResultsAction
{
    public function __invoke(string $orderId, ?array $notificationPayload = null): array
    {
        Log::info('🟢 GetGDAResultsAction iniciado', [
            'order_id' => $orderId,
            'has_payload' => !empty($notificationPayload)
        ]);

        if (!$notificationPayload) {
            throw new Exception('Se requiere el payload de la notificación');
        }

        $marca = $notificationPayload['header']['marca'] ?? null;
        $convenio = $notificationPayload['requisition']['convenio'] ?? null;
        $requisitionValue = $notificationPayload['requisition']['value'] ?? null;

        if (!$marca || !$convenio) {
            throw new Exception('Faltan datos marca o convenio en el payload');
        }

        $brandConfig = $this->findBrandByMarcaAndConvenio($marca, $convenio);

        if (!$brandConfig) {
            throw new Exception("No se encontró configuración para marca={$marca}, convenio={$convenio}");
        }

        $url = config('services.gda.url') . '/consult';

        $payload = [
            "header" => [
                "lineanegocio" => "",
                "registro" => now()->format('Y-m-d\TH:i:s:v'),
                "marca" => (int) $marca,
                "token" => $brandConfig['token'],
            ],
            "resourceType" => "ServiceRequest",
            "id" => (string) $orderId,
            "requisition" => [
                "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                "value" => $requisitionValue,
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

        Log::info('📤 Enviando petición a GDA', [
            'url' => $url,
            'order_id' => $orderId
        ]);

        Log::info('📦 PAYLOAD EXACTO ENVIADO A GDA', $payload);
        $response = Http::timeout(60)->post($url, $payload);

        $responseData = $response->json();

        Log::info('📥 Respuesta GDA', [
            'status' => $response->status(),
            'has_pdf' => !empty($responseData['infogda_resultado_b64'])
        ]);

        if ($response->failed()) {
            throw new Exception(
                'Error GDA: ' . ($responseData['GDA_menssage']['descripcion'] ?? $response->body())
            );
        }

        if (empty($responseData['infogda_resultado_b64'])) {
            throw new Exception('La respuesta no contiene resultados PDF');
        }

        return $responseData;
    }

    private function findBrandByMarcaAndConvenio(int $marca, int $convenio): ?array
    {
        $brands = config('services.gda.brands', []);

        Log::info('🔍 [ACTION] Buscando en brands config', [
            'brands_disponibles' => count($brands)
        ]);

        foreach ($brands as $key => $config) {
            $brandId = (int) ($config['brand_id'] ?? 0);
            $agreementId = (int) ($config['brand_agreement_id'] ?? 0);

            $matchByMarca = ($brandId === $marca);
            $matchByConvenio = ($agreementId === $convenio);

            if ($matchByMarca || $matchByConvenio) {
                Log::info('✅ [ACTION] Brand encontrado:', [
                    'brand_key' => $key,
                    'brand_id_config' => $brandId,
                    'agreement_id_config' => $agreementId,
                    'match_by' => $matchByMarca ? 'MARCA' : 'CONVENIO'
                ]);

                return array_merge($config, ['key' => $key]);
            }
        }

        Log::warning('⚠️ [ACTION] No se encontró brand', [
            'marca' => $marca,
            'convenio' => $convenio
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
                //"infogda_orden"=> "122455",

                "infogda_muestras" => [
                    [
                        //"infogda_etiqueta" => $orderId . "02",
                        "infogda_etiqueta" => "FB0L12245502",
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
}