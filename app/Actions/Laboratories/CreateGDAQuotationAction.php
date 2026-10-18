<?php

namespace App\Actions\Laboratories;

use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use App\Exceptions\GdaOrderDefinitiveException;
use App\Exceptions\GdaOrderResultUncertainException;
use App\Services\Laboratory\GdaOrderConfirmation;
use Throwable;
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

        $nonProductionEnvs = ['local', 'staging', 'testing'];
        $currentEnv = strtolower((string) config('app.env'));
        if (in_array($currentEnv, $nonProductionEnvs, true)) {
            Log::warning('CreateGDAQuotationAction: Entorno no productivo, retornando ID simulado', [
                'app_env' => config('app.env'),
                'normalized_env' => $currentEnv,
                'generated_id' => uniqid(),
            ]);

            return ['id' => strtoupper(uniqid('GDA')), 'infogda_consecutivo' => random_int(10000000, 99999999)];
        }

        $url = config('services.gda.url') . 'infogda-fullV3/service-request';
        
        Log::info('CreateGDAQuotationAction: URL configurada', ['url' => $url]);

        try {
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

        } catch (Throwable) {
            throw new GdaOrderDefinitiveException;
        }

        try {
            $response = Http::post($url, $payload);
            $responseData = $response->json();
            if (! $response->successful() || ! is_array($responseData) || ! GdaOrderConfirmation::valid($responseData)) {
                throw new GdaOrderResultUncertainException;
            }
            return $responseData;
        } catch (Throwable $exception) {
            $safe = $exception instanceof GdaOrderResultUncertainException
                ? $exception : new GdaOrderResultUncertainException;
            \App\Services\Laboratory\GdaPaymentDiagnostics::record('warning', 'gda_order_request_uncertain', $safe->context());
            throw $safe;
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
            
            $details[] = $detail;
        }

        Log::info('CreateGDAQuotationAction: Detalles construidos', [
            'details_count' => count($details)
        ]);

        return $details;
    }
}