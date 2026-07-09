<?php

namespace App\Actions\Laboratories;

use App\Exceptions\GdaResultsNotAvailableException;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetGDAResultsAction
{
    public function __construct(
        protected ResolveConsultableGdaId $resolveConsultableGdaId,
    ) {
    }

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

        $resolved = ($this->resolveConsultableGdaId)($orderId, $notificationPayload);
        $resolvedId = $resolved['id'];
        $resolvedSource = $resolved['source'];

        Log::info('GDA results consult id resolved', [
            'requested_order_id' => $orderId,
            'payload_id_original' => data_get($notificationPayload, 'id'),
            'infogda_etiqueta' => data_get($notificationPayload, 'code.coding.0.infogda_muestras.0.infogda_etiqueta'),
            'requisition_value' => data_get($notificationPayload, 'requisition.value'),
            'resolved_consult_id' => $resolvedId,
            'resolved_consult_id_source' => $resolvedSource,
        ]);

        $payload['id'] = $resolvedId;

        unset($payload['GDA_menssage']);

        Log::info('GDA results consult payload prepared', [
            'requested_order_id' => $orderId,
            'payload_id' => data_get($payload, 'id'),
            'requisition_value' => data_get($payload, 'requisition.value'),
            'is_payload_id_same_as_requested_order_id' => data_get($payload, 'id') === $orderId,
            'resolved_consult_id_source' => $resolvedSource,
        ]);

        $response = Http::timeout(60)->post($url, $payload);

        $responseData = $response->json();

        Log::info('GDA results consult response', [
            'status' => $response->status(),
            'has_pdf' => ! empty($responseData['infogda_resultado_b64']),
        ]);

        if ($response->failed()) {
            $message = $responseData['GDA_menssage']['descripcion'] ?? $response->body();

            if ($this->isInvalidConsultIdFormatMessage($message)) {
                Log::error('GDA results consult id format rejected', [
                    'requested_order_id' => $orderId,
                    'resolved_consult_id' => $resolvedId,
                    'resolved_consult_id_source' => $resolvedSource,
                    'status' => $response->status(),
                    'message' => $message,
                ]);
            }

            if ($response->status() === 400 && $this->isResultsNotAvailableMessage($message)) {
                Log::warning('GDA results not available yet', [
                    'order_id' => $orderId,
                    'payload_id' => data_get($payload, 'id'),
                    'requisition_value' => data_get($payload, 'requisition.value'),
                    'resolved_consult_id_source' => $resolvedSource,
                    'status' => $response->status(),
                    'message' => $message,
                ]);

                throw new GdaResultsNotAvailableException(
                    orderId: $orderId,
                    gdaMessage: $message,
                );
            }

            throw new Exception('Error GDA: '.$message);
        }

        if (empty($responseData['infogda_resultado_b64'])) {
            throw new Exception('La respuesta no contiene resultados PDF');
        }

        return $responseData;
    }

    private function isResultsNotAvailableMessage(string $message): bool
    {
        return str_contains(mb_strtolower($message), 'no contiene resultados');
    }

    private function isInvalidConsultIdFormatMessage(string $message): bool
    {
        $normalized = mb_strtolower($message);

        return str_contains($normalized, 'no cumple con las especificaciones')
            || str_contains($normalized, 'formato correcto');
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
