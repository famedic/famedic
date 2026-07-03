<?php

namespace App\Actions\Laboratories;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetGDAResultsAction
{
    public function __invoke(string $orderId, ?array $notificationPayload = null): array
    {
        Log::info('GetGDAResultsAction iniciado', [
            'order_id' => $orderId,
            'has_payload' => ! empty($notificationPayload),
        ]);

        if (! $notificationPayload) {
            throw new Exception('Se requiere el payload de la notificación');
        }

        $marca = $notificationPayload['header']['marca'] ?? null;
        $convenio = $notificationPayload['requisition']['convenio'] ?? null;

        if (! $marca || ! $convenio) {
            throw new Exception('Faltan datos marca o convenio en el payload');
        }

        $brandConfig = $this->findBrandByMarcaAndConvenio($marca, $convenio);

        if (! $brandConfig) {
            throw new Exception("No se encontró configuración para marca={$marca}, convenio={$convenio}");
        }

        $url = config('services.gda.url').'infogda-fullV3/consult';

        $payload = $notificationPayload;

        $payload['header']['token'] = $brandConfig['token'];
        $payload['header']['registro'] = now()->format('Y-m-d\TH:i:s:v');
        $payload['id'] = $this->resolvePayloadId($orderId, $notificationPayload);

        unset($payload['GDA_menssage']);

        Log::info('GDA results consult payload prepared', [
            'requested_order_id' => $orderId,
            'payload_id' => data_get($payload, 'id'),
            'requisition_value' => data_get($payload, 'requisition.value'),
            'is_payload_id_same_as_requested_order_id' => data_get($payload, 'id') === $orderId,
        ]);

        $response = Http::timeout(60)->post($url, $payload);

        $responseData = $response->json();

        Log::info('GDA results consult response', [
            'status' => $response->status(),
            'has_pdf' => ! empty($responseData['infogda_resultado_b64']),
        ]);

        if ($response->failed()) {
            throw new Exception(
                'Error GDA: '.($responseData['GDA_menssage']['descripcion'] ?? $response->body())
            );
        }

        if (empty($responseData['infogda_resultado_b64'])) {
            throw new Exception('La respuesta no contiene resultados PDF');
        }

        return $responseData;
    }

    /**
     * El $orderId recibido por el caller (gda_order_id de la notificación/compra)
     * siempre tiene prioridad como identificador para la consulta GDA.
     *
     * Para gabinete: $orderId = "GZ0L000515", requisition.value = "2066" (ID externo).
     * Para laboratorio: $orderId = "24642071", requisition.value = "HD0L001392".
     *
     * GDA espera en el campo "id" la etiqueta con formato (([A-Z0-9]{3})(L)([0-9]{6}))
     * para gabinete, o el ID numérico para laboratorio. En ambos casos, $orderId es
     * el valor correcto.
     */
    private function resolvePayloadId(string $orderId, array $notificationPayload): string
    {
        if ($orderId !== '') {
            return $orderId;
        }

        return data_get($notificationPayload, 'id')
            ?? data_get($notificationPayload, 'code.coding.0.infogda_muestras.0.infogda_etiqueta')
            ?? data_get($notificationPayload, 'requisition.value')
            ?? $orderId;
    }

    private function findBrandByMarcaAndConvenio(int $marca, int $convenio): ?array
    {
        $brands = config('services.gda.brands', []);

        foreach ($brands as $key => $config) {
            $brandId = (int) ($config['brand_id'] ?? 0);
            $agreementId = (int) ($config['brand_agreement_id'] ?? 0);

            if ($brandId === $marca || $agreementId === $convenio) {
                Log::info('GDA brand config matched', [
                    'brand_key' => $key,
                    'brand_id_config' => $brandId,
                    'agreement_id_config' => $agreementId,
                ]);

                return array_merge($config, ['key' => $key]);
            }
        }

        Log::warning('GDA brand config not found', [
            'marca' => $marca,
            'convenio' => $convenio,
        ]);

        return null;
    }
}