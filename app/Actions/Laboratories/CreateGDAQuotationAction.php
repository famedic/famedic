<?php

namespace App\Actions\Laboratories;

use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        if (app()->environment('local')) {
            Log::warning('CreateGDAQuotationAction: Ejecutando en entorno local, retornando ID simulado', [
                'generated_id' => uniqid()
            ]);
            return ['id' => uniqid()];
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
            
            // Log de la respuesta completa
            $responseData = $response->json();
            
            Log::info('CreateGDAQuotationAction: Respuesta recibida de API GDA', [
                'status_code' => $response->status(),
                'success' => $response->successful(),
                'failed' => $response->failed(),
                'response_body' => $responseData,
                'response_headers' => $response->headers()
            ]);

            // Log específico si hay errores
            if ($response->failed()) {
                Log::error('CreateGDAQuotationAction: La API respondió con error', [
                    'status' => $response->status(),
                    'body' => $responseData,
                    'laboratoryPurchaseId' => $laboratoryPurchaseId
                ]);
                throw new Exception('Error en API GDA: ' . ($responseData['message'] ?? 'Error desconocido'));
            }

            Log::info('CreateGDAQuotationAction: Proceso completado exitosamente', [
                'response_id' => $responseData['id'] ?? null,
                'laboratoryPurchaseId' => $laboratoryPurchaseId
            ]);

            return $responseData;

        } catch (Exception $e) {
            Log::error('CreateGDAQuotationAction: Excepción capturada', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'laboratoryPurchaseId' => $laboratoryPurchaseId
            ]);
            throw $e;
        }
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