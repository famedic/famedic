<?php

namespace App\Actions\Laboratories;

use App\Models\Address;
use App\Models\Contact;
use App\Models\Customer;
use Exception;
use Illuminate\Support\Facades\Http;

class CreatePatientAction
{
    public function __invoke(Customer $customer, Contact $contact, Address $address, string $brand): int
    {
        $url = config('services.gda.url') . 'infogda-fullV3/patient';

        $payload = [
            "header" => [
                "lineanegocio" => "De donde proviene",
                "registro" => localizedDate(now())->isoFormat('YYYY-MM-DD\THH:mm:ss:SSS'),
                "marca" => config('services.gda.brands.' . $brand . '.brand_id'),
                "token" => config('services.gda.brands.' . $brand . '.token'),
            ],
            "resourceType" => "Patient",
            "id" => "1",
            "status" => "new",
            "meta" => [
                "versionId" => "1",
                "lastUpdated" => localizedDate(now())->isoFormat('YYYY-MM-DD\THH:mm:ss:SSS')
            ],
            "identifier" => [
                [
                    "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                    "value" => $customer->id,
                    "convenio" => config('services.gda.brands.' . $brand . '.brand_agreement_id'),
                    "infogda_kpaciente" => ""
                ]
            ],
            "active" => true,
            "name" => [
                [
                    "text" => strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $contact->full_name)),
                    "family" => strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $contact->paternal_lastname)),
                    "given" => [
                        strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $contact->name))
                    ],
                    "extension" => [
                        "url" => "https://www.hl7.org/fhir/extension-humanname-mothers-family.html",
                        "valueString" => strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $contact->maternal_lastname))
                    ]
                ]
            ],
            "telecom" => [
                [
                    "system" => "phone",
                    "value" => $contact->phone->getRawNumber()
                ],
                [
                    "system" => "email",
                    "value" => $customer->user->email
                ]
            ],
            "gender" => $contact->gender->value == 2  ? 'female' : 'male',
            "birthDate" => $contact->birth_date->isoFormat('DD-MM-YYYY'),
            "address" => [
                [
                    "line" => [
                        strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $address->street . ' ' . $address->number))
                    ],
                    "city" => strtoupper($address->city),
                    "district" => strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $address->neighborhood)),
                    "state" => strtoupper($address->state),
                    "postalCode" => $address->zipcode,
                    "country" => "MEXICO"
                ]
            ],
            "GDA_menssage" => [
                "codeHttp" => "",
                "mensaje" => "error|success",
                "descripcion" => "",
                "acuse" => ""
            ]
        ];

        $response = Http::post($url, $payload);

        logger($response->json());

        if ($response->failed()) {
            throw new Exception();
        }

        return $response->json()['identifier'][0]['infogda_kpaciente'];
    }
}
