<?php

namespace App\Actions\Laboratories;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessGDANotificationAction
{
    public function __invoke(array $notificationData, string $brand): array
    {
        // Para entorno local, procesar datos de prueba
        /*if (app()->environment('local')) {
            return $this->processMockNotification($notificationData, $brand);
        }*/

        try {
            $url = config('services.gda.url') . '/notification';
            
            $payload = $this->buildNotificationPayload($notificationData, $brand);

            Log::info('Enviando notificación a GDA:', $payload);

            $response = Http::timeout(30)->post($url, $payload);

            $responseData = $response->json();
            
            Log::info('Respuesta de notificación GDA:', $responseData);

            if ($response->failed()) {
                throw new Exception('Error al enviar notificación al laboratorio: ' . $response->body());
            }

            // Procesar la respuesta y guardar en base de datos
            return $this->processNotificationResponse($responseData, $notificationData);

        } catch (Exception $e) {
            Log::error('Error en ProcessGDANotificationAction: ' . $e->getMessage());
            throw $e;
        }
    }

    private function buildNotificationPayload(array $data, string $brand): array
    {
        return [
            "header" => [
                "lineanegocio" => "Notificasion-Resultados",
                "registro" => now()->isoFormat('YYYY-MM-DD\THH:mm:ss:SSS'),
                "marca" => (int) config('services.gda.brands.' . $brand . '.brand_id'),
                "token" => config('services.gda.brands.' . $brand . '.token'),
            ],
            "resourceType" => "ServiceRequest",
            "id" => $data['order_id'] ?? '',
            "requisition" => [
                "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                "value" => $data['external_identifier'] ?? 'MDPROD-' . $data['order_id'],
                "convenio" => (int) ($data['agreement_id'] ?? config('services.gda.brands.' . $brand . '.brand_agreement_id')),
            ],
            "status" => $data['status'] ?? 'completed',
            "intent" => "order",
            "priority" => $data['priority'] ?? 'routine',
            "code" => [
                "coding" => $this->buildNotificationCoding($data),
            ],
            "orderdetail" => $data['order_detail'] ?? 'Liberado',
            "quantityQuantity" => $data['quantity'] ?? '1',
            "subject" => [
                "reference" => "Patient/" . ($data['patient_id'] ?? '')
            ],
            "requester" => [
                "reference" => "Practitioner/" . ($data['practitioner_id'] ?? ''),
                "display" => $data['practitioner_name'] ?? 'A QUIEN CORRESPONDA'
            ],
            "GDA_menssage" => [
                "codeHttp" => 0,
                "mensaje" => "success",
                "descripcion" => "",
                "acuse" => $data['acuse'] ?? ''
            ]
        ];
    }

    private function buildNotificationCoding(array $data): array
    {
        $coding = [
            "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
            "code" => $data['exam_code'] ?? '',
            "display" => $data['exam_name'] ?? '',
            "infogda_status" => $data['exam_status'] ?? 'completed',
            "infogda_cexamen" => $data['exam_code'] ?? '',
            "infogda_orden" => $data['order_id'] ?? '',
        ];

        // Agregar muestras si están presentes
        if (!empty($data['samples'])) {
            $coding["infogda_muestras"] = array_map(function ($sample) {
                return [
                    "infogda_etiqueta" => $sample['label'] ?? '',
                    "infogda_contenedor" => $sample['container'] ?? '',
                    "infogda_muestra" => $sample['sample_type'] ?? '',
                    "infogda_kmuestrasucursal" => $sample['branch_sample_id'] ?? 0,
                    "infogda_contenedoracronim" => $sample['container_acronym'] ?? ''
                ];
            }, $data['samples']);
        } else {
            $coding["infogda_muestras"] = [];
        }

        // Agregar preanalíticos si están presentes
        $coding["infogda_preanaliticos"] = $data['preanalytics'] ?? [];

        return [$coding];
    }

    private function processNotificationResponse(array $response, array $originalData): array
    {
        // Aquí puedes agregar la lógica para guardar en tu base de datos
        $notificationRecord = [
            'order_id' => $originalData['order_id'] ?? '',
            'external_identifier' => $originalData['external_identifier'] ?? '',
            'patient_id' => $originalData['patient_id'] ?? '',
            'practitioner_id' => $originalData['practitioner_id'] ?? '',
            'exam_code' => $originalData['exam_code'] ?? '',
            'exam_name' => $originalData['exam_name'] ?? '',
            'status' => $originalData['status'] ?? 'completed',
            'gda_response' => $response,
            'acuse' => $response['GDA_menssage']['acuse'] ?? '',
            'http_code' => $response['GDA_menssage']['codeHttp'] ?? 0,
            'message' => $response['GDA_menssage']['mensaje'] ?? '',
            'description' => $response['GDA_menssage']['descripcion'] ?? '',
            'notification_date' => now(),
        ];

        // TODO: Guardar en tu modelo de base de datos
        // Ejemplo: Notification::create($notificationRecord);

        return [
            'success' => ($response['GDA_menssage']['codeHttp'] ?? 0) === 200,
            'acuse' => $response['GDA_menssage']['acuse'] ?? '',
            'message' => $response['GDA_menssage']['mensaje'] ?? '',
            'description' => $response['GDA_menssage']['descripcion'] ?? '',
            'notification_record' => $notificationRecord
        ];
    }

    private function processMockNotification(array $data, string $brand): array
    {
        $mockResponse = [
            "header" => [
                "lineanegocio" => "Notificasion-Resultados",
                "registro" => now()->format('M j, Y g:i:s A'),
                "marca" => config('services.gda.brands.' . $brand . '.brand_id'),
                "token" => "mock_token_" . time()
            ],
            "resourceType" => "ServiceRequest",
            "id" => $data['order_id'] ?? 'MOCK123',
            "requisition" => [
                "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                "value" => $data['external_identifier'] ?? 'MDPROD-MOCK123',
                "convenio" => (int) config('services.gda.brands.' . $brand . '.brand_agreement_id'),
            ],
            "status" => "completed",
            "intent" => "order",
            "priority" => "routine",
            "code" => [
                "coding" => $this->buildNotificationCoding($data),
            ],
            "orderdetail" => "Liberado",
            "quantityQuantity" => "1",
            "subject" => [
                "reference" => "Patient/11891633"
            ],
            "requester" => [
                "reference" => "Practitioner/7896616",
                "display" => "1018 JOSE CARLOS CESSA ZANATTA"
            ],
            "GDA_menssage" => [
                "codeHttp" => 200,
                "mensaje" => "success",
                "descripcion" => "Notificación procesada exitosamente",
                "acuse" => "mock-acuse-" . uniqid()
            ]
        ];

        return $this->processNotificationResponse($mockResponse, $data);
    }
}