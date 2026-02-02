<?php

namespace App\Actions\Laboratories;

use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use Exception;
use Illuminate\Support\Facades\Http;

class BackupCreateGDAQuotationAction
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
        if (app()->environment(environments: 'local')) {
            return ['id' => uniqid()];
        }

        $url = config('services.gda.url') . '/service-request-cotizacion';
        dd($url);
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

        $response = Http::post($url, $payload);

        logger($response->json());

        if ($response->failed()) {
            throw new Exception();
        }

        return $response->json();
    }

    private function buildCoding(array $laboratoryTestsDetail)
    {
        $coding = [];

        foreach ($laboratoryTestsDetail as $item) {
            $coding[] =
                [
                    "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                    "code" => $item['code'],
                    "display" => $item['name'],
                    "infogda_status" => "on-hold",
                    "infogda_muestras" => [],
                    "infogda_preanaliticos" => [],
                ];
        }

        return $coding;
    }

    private function buildDetails($laboratoryCartItems)
    {
        $details = [];

        foreach ($laboratoryCartItems as $laboratoryCartItem) {
            $details[] = [
                'code' => $laboratoryCartItem->laboratoryTest->gda_id,
                'name' => $laboratoryCartItem->laboratoryTest->name,
                'price' => $laboratoryCartItem->laboratoryTest->famedic_price_cents
            ];
        }

        return $details;
    }
}
