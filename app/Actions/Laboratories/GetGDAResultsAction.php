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

        if (!$marca || !$convenio) {
            throw new Exception('Faltan datos marca o convenio en el payload');
        }

        $brandConfig = $this->findBrandByMarcaAndConvenio($marca, $convenio);

        if (!$brandConfig) {
            throw new Exception("No se encontró configuración para marca={$marca}, convenio={$convenio}");
        }

        //$url = config('services.gda.consult_url');
        $url = config('services.gda.url') . 'infogda-fullV3/consult';

        /**
         * Usamos el payload original enviado por GDA
         */
        $payload = $notificationPayload;

        /**
         * Ajustamos el header
         */
        $payload['header']['token'] = $brandConfig['token'];
        $payload['header']['registro'] = now()->format('Y-m-d\TH:i:s:v');
        $payload['id'] = $notificationPayload['requisition']['value'];
        
        /**
         * Eliminamos campos que no deben enviarse
         */
        unset($payload['GDA_menssage']);

        Log::info('📦 PAYLOAD FINAL ENVIADO A GDA', $payload);

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

            if ($brandId === $marca || $agreementId === $convenio) {

                Log::info('✅ [ACTION] Brand encontrado', [
                    'brand_key' => $key,
                    'brand_id_config' => $brandId,
                    'agreement_id_config' => $agreementId
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
}