<?php

namespace App\Actions\Laboratories;

use App\Exceptions\GdaResultsNotAvailableException;
use App\Support\GDA\GdaPayloadSanitizer;
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
        $prepared = $this->prepareConsultRequest($orderId, $notificationPayload);
        $result = $this->executeConsult($prepared);

        if ($result['failed']) {
            $this->throwForFailedConsult($orderId, $prepared, $result);
        }

        if (empty($result['response']['infogda_resultado_b64'] ?? null)) {
            throw new Exception('La respuesta no contiene resultados PDF');
        }

        return $result['response'];
    }

    /**
     * Prepara el payload exacto que se enviaría a GDA (token, registro e id consultable).
     *
     * @return array{
     *     url: string,
     *     payload: array,
     *     resolved_id: string,
     *     resolved_source: string,
     *     brand_key: string|null,
     *     requested_order_id: string
     * }
     */
    public function prepareConsultRequest(string $orderId, ?array $notificationPayload = null): array
    {
        Log::info('GetGDAResultsAction prepareConsultRequest', [
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

        $brandConfig = $this->findBrandByMarcaAndConvenio((int) $marca, (int) $convenio);

        if (! $brandConfig) {
            throw new Exception("No se encontró configuración para marca={$marca}, convenio={$convenio}");
        }

        $url = $this->resultsConsultUrl();

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

        return [
            'url' => $url,
            'payload' => $payload,
            'resolved_id' => $resolvedId,
            'resolved_source' => $resolvedSource,
            'brand_key' => $brandConfig['key'] ?? null,
            'requested_order_id' => $orderId,
        ];
    }

    /**
     * Ejecuta la consulta a GDA y devuelve request/response para depuración (sin persistir).
     *
     * @return array{
     *     success: bool,
     *     url: string,
     *     http_status: int|null,
     *     resolved_id: string,
     *     resolved_source: string,
     *     brand_key: string|null,
     *     requested_order_id: string,
     *     request_payload: array,
     *     response_payload: array|null,
     *     has_pdf: bool,
     *     error: string|null,
     *     gda_not_available: bool
     * }
     */
    public function consultForDebug(string $orderId, ?array $notificationPayload = null): array
    {
        $prepared = $this->prepareConsultRequest($orderId, $notificationPayload);
        $result = $this->executeConsult($prepared);

        $error = null;
        $gdaNotAvailable = false;
        $success = ! $result['failed'] && ! empty($result['response']['infogda_resultado_b64'] ?? null);

        if ($result['failed']) {
            $message = $result['response']['GDA_menssage']['descripcion']
                ?? $result['raw_body']
                ?? 'Error desconocido de GDA';

            if ($result['http_status'] === 400 && $this->isResultsNotAvailableMessage((string) $message)) {
                $gdaNotAvailable = true;
                $error = (string) $message;
            } else {
                $error = 'Error GDA: '.$message;
            }
        } elseif (! $success) {
            $error = 'La respuesta no contiene resultados PDF';
        }

        $pdfBase64 = is_array($result['response'])
            ? GdaPayloadSanitizer::extractResultsPdfBase64($result['response'])
            : null;

        return [
            'success' => $success,
            'url' => $prepared['url'],
            'http_status' => $result['http_status'],
            'resolved_id' => $prepared['resolved_id'],
            'resolved_source' => $prepared['resolved_source'],
            'brand_key' => $prepared['brand_key'],
            'requested_order_id' => $prepared['requested_order_id'],
            'request_payload' => GdaPayloadSanitizer::sanitizeForDebug($prepared['payload']),
            'response_payload' => is_array($result['response'])
                ? GdaPayloadSanitizer::sanitizeForDebug($result['response'])
                : ['raw_body' => $result['raw_body']],
            'pdf_base64' => $pdfBase64,
            'has_pdf' => ! empty($pdfBase64),
            'error' => $error,
            'gda_not_available' => $gdaNotAvailable,
        ];
    }

    /**
     * @param  array{
     *     url: string,
     *     payload: array,
     *     resolved_id: string,
     *     resolved_source: string,
     *     brand_key: string|null,
     *     requested_order_id: string
     * }  $prepared
     * @return array{failed: bool, http_status: int|null, response: array|null, raw_body: string|null}
     */
    private function executeConsult(array $prepared): array
    {
        $response = Http::timeout(60)->post($prepared['url'], $prepared['payload']);
        $responseData = $response->json();

        Log::info('GDA results consult response', [
            'status' => $response->status(),
            'has_pdf' => ! empty($responseData['infogda_resultado_b64']),
        ]);

        return [
            'failed' => $response->failed(),
            'http_status' => $response->status(),
            'response' => is_array($responseData) ? $responseData : null,
            'raw_body' => $response->body(),
        ];
    }

    /**
     * @param  array{
     *     url: string,
     *     payload: array,
     *     resolved_id: string,
     *     resolved_source: string,
     *     brand_key: string|null,
     *     requested_order_id: string
     * }  $prepared
     * @param  array{failed: bool, http_status: int|null, response: array|null, raw_body: string|null}  $result
     */
    private function throwForFailedConsult(string $orderId, array $prepared, array $result): void
    {
        $message = $result['response']['GDA_menssage']['descripcion'] ?? $result['raw_body'] ?? 'Error desconocido';

        if ($this->isInvalidConsultIdFormatMessage((string) $message)) {
            Log::error('GDA results consult id format rejected', [
                'requested_order_id' => $orderId,
                'resolved_consult_id' => $prepared['resolved_id'],
                'resolved_consult_id_source' => $prepared['resolved_source'],
                'status' => $result['http_status'],
                'message' => $message,
            ]);
        }

        if ($result['http_status'] === 400 && $this->isResultsNotAvailableMessage((string) $message)) {
            Log::warning('GDA results not available yet', [
                'order_id' => $orderId,
                'payload_id' => data_get($prepared['payload'], 'id'),
                'requisition_value' => data_get($prepared['payload'], 'requisition.value'),
                'resolved_consult_id_source' => $prepared['resolved_source'],
                'status' => $result['http_status'],
                'message' => $message,
            ]);

            throw new GdaResultsNotAvailableException(
                orderId: $orderId,
                gdaMessage: (string) $message,
            );
        }

        throw new Exception('Error GDA: '.$message);
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
        $partialMatch = null;

        foreach ($brands as $key => $config) {
            $brandId = (int) ($config['brand_id'] ?? 0);
            $agreementId = (int) ($config['brand_agreement_id'] ?? 0);

            if ($brandId === $marca && $agreementId === $convenio) {
                Log::info('GDA brand config matched', [
                    'brand_key' => $key,
                    'match' => 'exact',
                    'brand_id_config' => $brandId,
                    'agreement_id_config' => $agreementId,
                ]);

                return array_merge($config, ['key' => $key]);
            }

            if ($partialMatch === null && ($brandId === $marca || $agreementId === $convenio)) {
                $partialMatch = array_merge($config, ['key' => $key]);
            }
        }

        if ($partialMatch) {
            Log::info('GDA brand config matched', [
                'brand_key' => $partialMatch['key'],
                'match' => 'partial',
                'brand_id_config' => (int) ($partialMatch['brand_id'] ?? 0),
                'agreement_id_config' => (int) ($partialMatch['brand_agreement_id'] ?? 0),
            ]);

            return $partialMatch;
        }

        Log::warning('GDA brand config not found', [
            'marca' => $marca,
            'convenio' => $convenio,
        ]);

        return null;
    }

    /**
     * Endpoint de consulta de PDF. Usa GDA_RESULTS_CONSULT_URL cuando está definida;
     * el resto de APIs GDA siguen en GDA_URL.
     */
    public function resultsConsultUrl(): string
    {
        $configured = config('services.gda.results_consult_url');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return rtrim((string) config('services.gda.url'), '/').'/infogda-fullV3/consult';
    }
}
