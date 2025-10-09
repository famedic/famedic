<?php

namespace App\Actions\Laboratories;

use Exception;
use Illuminate\Support\Facades\Http;

class CreatePractitionerAction
{
    public function __invoke(string $brand): int
    {
        $url = config('services.gda.url') . 'infogda-fullV3/practitioner';

        $payload = [
            "header" => [
                "lineanegocio" => "De donde proviene",
                "registro" => localizedDate(now())->isoFormat('YYYY-MM-DD\THH:mm:ss:SSS'),
                "marca" => config('services.gda.brands.' . $brand . '.brand_id'),
                "token" => config('services.gda.brands.' . $brand . '.token'),
            ],
            "resourceType" => "Practitioner",
            "id" => "1",
            "status" => "new",
            "identifier" => [
                [
                    "system" => "urn:oid:2.16.840.1.113883.3.215.5.59",
                    "value" => "0001",
                    "convenio" => config('services.gda.brands.' . $brand . '.brand_agreement_id'),
                ]
            ],
            "active" => true,
            "name" => [
                [
                    "family" => "FAMEDIC",
                    "given" => [
                        "FAMEDIC"
                    ],
                    "prefix" => [
                        "Dr."
                    ],
                    "extension" => [
                        "url" => "https://www.hl7.org/fhir/extension-humanname-mothers-family.html",
                        "valueString" => "FAMEDIC"
                    ]
                ]
            ],
            "telecom" => [
                [
                    "system" => "phone",
                    "value" => "5555555555"
                ],
                [
                    "system" => "email",
                    "value" => 'contacto@famedic.com.mx'
                ]
            ],
            "gender" => "male",
            "birthDate" => "01-01-1999",
            "address" => [
                [
                    "line" => [
                        'AVENIDA JOSE CLEMENTE OROZCO 335 202'
                    ],
                    "city" => 'SAN PEDRO GARZA GARCIA',
                    "district" => 'DEL VALLE SECT ORIEN',
                    "state" => 'NLE',
                    "postalCode" => '66269',
                    "country" => "Mexico"
                ]
            ],
            "qualification" => [
                [
                    "identifier" => [
                        [
                            "value" => "3456789"
                        ]
                    ]
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

        return $response->json()['identifier'][0]['infogda_cmedico'];
    }
}
