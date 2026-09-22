<?php

namespace App\Actions\Laboratories;

use App\Exceptions\GdaOrderResultUncertainException;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateGDAQuotationAction
{
    private CreatePatientAction $createPatientAction;
    private CreatePractitionerAction $createPractitionerAction;

    public function __construct(
        CreatePatientAction $createPatientAction,
        CreatePractitionerAction $createPractitionerAction
    ) {
        $this->createPatientAction = $createPatientAction;
        $this->createPractitionerAction = $createPractitionerAction;
    }

    public function __invoke(Customer $customer, Address $address, Contact $contact, string $brand, $laboratoryCartItems, $laboratoryPurchaseId): array
    {
        Log::info('=== INICIO CreateGDAQuotationAction ===', [
            'customer_id' => $customer->id,
            'brand' => $brand,
            'laboratoryPurchaseId' => $laboratoryPurchaseId,
            'items_count' => $laboratoryCartItems->count(),
            'environment' => app()->environment()
        ]);

        $nonProductionEnvs = ['local', 'staging', 'testing'];
        $currentEnv = strtolower((string) config('app.env'));
        if (in_array($currentEnv, $nonProductionEnvs, true)) {
            $generatedId = strtoupper(uniqid('GDA'));
            $generatedConsecutive = random_int(10000000, 99999999);

            Log::warning('CreateGDAQuotationAction: Entorno no productivo, retornando orden simulada', [
                'app_env' => config('app.env'),
                'normalized_env' => $currentEnv,
                'generated_id' => $generatedId,
                'generated_consecutive' => $generatedConsecutive,
            ]);

            return [
                'id' => $generatedId,
                'infogda_consecutivo' => $generatedConsecutive,
                'gda_mensaje' => 'simulated',
            ];
        }

        $url = config('services.gda.url') . 'infogda-fullV3/service-request';
        
        Log::info('CreateGDAQuotationAction: URL configurada', ['url' => $url]);

        // Construir payload
        $payload = [
            "header" => [
                "lineanegocio" => "Famedic Web",
                "registro" => localizedDate(now())->isoFormat('YYYY-MM-DD\THH:mm:ss:SSS'),
                "marca" => config('services.gda.brands.' . $brand . '.brand_id'),
                "token" => config('services.gda.brands.' . $brand . '.token'),
            ],
            "resourceType" => "ServiceRequest",
            "id" => "",
            "requisition" => [
                "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                "value" => $laboratoryPurchaseId,
                "convenio" => config('services.gda.brands.' . $brand . '.brand_agreement_id'),
            ],
            "status" => "active",
            "intent" => "order",
            "priority" => "routine",
            "code" => [
                "coding" => $this->buildCoding(
                    $this->buildDetails($laboratoryCartItems),
                    config('services.gda.brands.' . $brand . '.brand_agreement_id')
                ),
            ],
            "orderdetail" => "Check-up exam requested",
            "quantityQuantity" => $laboratoryCartItems->count(),
            "subject" => [
                "reference" => "Patient/" . (string)($this->createPatientAction)($customer, $contact, $address, $brand),
            ],
            "requester" => [
                "reference" => "Practitioner/" . (string)($this->createPractitionerAction)($brand),
                "display" => "A QUIEN CORRESPONDA"
            ],
        ];

        // Log del payload (con información sensible parcialmente oculta)
        $logPayload = $payload;
        if (isset($logPayload['header']['token'])) {
            $logPayload['header']['token'] = '***OCULTO***';
        }
        //Log::info('CreateGDAQuotationAction: Payload enviado a API', $logPayload);

        try {
            Log::info('CreateGDAQuotationAction: Enviando petición a API GDA');
            $response = Http::post($url, $payload);

            $responseData = $this->decodeJsonResponse($response, (int) $laboratoryPurchaseId);
            $responseSummary = $this->summarizeResponse($responseData);

            Log::info('CreateGDAQuotationAction: Respuesta recibida de API GDA', [
                'status_code' => $response->status(),
                'success' => $response->successful(),
                'failed' => $response->failed(),
                'response_summary' => $responseSummary,
            ]);

            if ($response->failed()) {
                Log::warning('CreateGDAQuotationAction: La API GDA respondió sin confirmación confiable', [
                    'status' => $response->status(),
                    'response_summary' => $responseSummary,
                    'laboratoryPurchaseId' => $laboratoryPurchaseId,
                ]);

                throw GdaOrderResultUncertainException::forResponse(
                    reason: 'gda_http_failed',
                    laboratoryPurchaseId: (int) $laboratoryPurchaseId,
                    httpStatus: $response->status(),
                    responseSummary: $responseSummary,
                );
            }

            $this->assertSemanticSuccess($responseData, (int) $laboratoryPurchaseId, $response->status());

            Log::info('CreateGDAQuotationAction: Proceso completado exitosamente', [
                'response_id' => $responseData['id'] ?? null,
                'infogda_consecutivo' => $responseData['infogda_consecutivo'] ?? null,
                'laboratoryPurchaseId' => $laboratoryPurchaseId
            ]);

            return $responseData;

        } catch (GdaOrderResultUncertainException $e) {
            Log::warning('CreateGDAQuotationAction: Resultado GDA incierto', $e->context());
            throw $e;
        } catch (ConnectionException $e) {
            Log::warning('CreateGDAQuotationAction: Error de conexión con GDA', [
                'message' => $e->getMessage(),
                'laboratoryPurchaseId' => $laboratoryPurchaseId,
            ]);

            throw GdaOrderResultUncertainException::forResponse(
                reason: 'gda_connection_error',
                laboratoryPurchaseId: (int) $laboratoryPurchaseId,
                previous: $e,
            );
        } catch (Throwable $e) {
            Log::warning('CreateGDAQuotationAction: Excepción inesperada durante creación GDA', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
                'laboratoryPurchaseId' => $laboratoryPurchaseId,
            ]);

            throw GdaOrderResultUncertainException::forResponse(
                reason: 'gda_unexpected_exception',
                laboratoryPurchaseId: (int) $laboratoryPurchaseId,
                previous: $e,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(Response $response, int $laboratoryPurchaseId): array
    {
        $responseData = $response->json();

        if (! is_array($responseData) || $responseData === []) {
            throw GdaOrderResultUncertainException::forResponse(
                reason: 'gda_empty_or_malformed_json',
                laboratoryPurchaseId: $laboratoryPurchaseId,
                httpStatus: $response->status(),
                responseSummary: [
                    'has_body' => trim($response->body()) !== '',
                    'body_length' => strlen($response->body()),
                ],
            );
        }

        return $responseData;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function assertSemanticSuccess(array $responseData, int $laboratoryPurchaseId, int $httpStatus): void
    {
        $folio = $this->normalizeRequiredValue($responseData['id'] ?? null);
        $consecutive = $this->normalizeRequiredValue($responseData['infogda_consecutivo'] ?? null);

        if ($folio === null || $folio === '0' || $consecutive === null) {
            throw GdaOrderResultUncertainException::forResponse(
                reason: 'gda_missing_required_identifiers',
                laboratoryPurchaseId: $laboratoryPurchaseId,
                httpStatus: $httpStatus,
                responseSummary: $this->summarizeResponse($responseData),
            );
        }
    }

    private function normalizeRequiredValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    private function summarizeResponse(array $responseData): array
    {
        return [
            'id' => $responseData['id'] ?? null,
            'infogda_consecutivo' => $responseData['infogda_consecutivo'] ?? null,
            'gda_code_http' => $responseData['gda_code_http'] ?? data_get($responseData, 'GDA_menssage.codeHttp'),
            'gda_mensaje' => $responseData['gda_mensaje'] ?? data_get($responseData, 'GDA_menssage.mensaje'),
            'gda_status' => $responseData['status'] ?? null,
            'has_pdf_base64' => filled($responseData['pdf_base64'] ?? null),
        ];
    }

    private function buildCoding(array $laboratoryTestsDetail)
    {
        /*Log::info('CreateGDAQuotationAction: Construyendo coding', [
            'items_count' => count($laboratoryTestsDetail)
        ]);
        */
        $coding = [];

        foreach ($laboratoryTestsDetail as $item) {
            $coding[] = [
                "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                "code" => $item['code'],
                "display" => $item['name'],
                "infogda_status" => "on-hold",
                "infogda_muestras" => [],
                "infogda_preanaliticos" => [],
            ];
        }

        Log::info('CreateGDAQuotationAction: Coding construido', [
            'coding_count' => count($coding)
        ]);

        return $coding;
    }

    private function buildDetails($laboratoryCartItems)
    {
        Log::info('CreateGDAQuotationAction: Construyendo detalles', [
            'items_count' => $laboratoryCartItems->count()
        ]);

        $details = [];

        foreach ($laboratoryCartItems as $laboratoryCartItem) {
            $detail = [
                'code' => $laboratoryCartItem->laboratoryTest->gda_id,
                'name' => $laboratoryCartItem->laboratoryTest->name,
                'price' => $laboratoryCartItem->laboratoryTest->famedic_price_cents
            ];
            
            Log::debug('CreateGDAQuotationAction: Item procesado', [
                'test_id' => $laboratoryCartItem->laboratoryTest->id,
                'gda_id' => $laboratoryCartItem->laboratoryTest->gda_id,
                'name' => $laboratoryCartItem->laboratoryTest->name
            ]);
            
            $details[] = $detail;
        }

        Log::info('CreateGDAQuotationAction: Detalles construidos', [
            'details_count' => count($details)
        ]);

        return $details;
    }
}
