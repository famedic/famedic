<?php

namespace App\Actions\OnlinePharmacy;

use Exception;
use Illuminate\Support\Facades\Http;

class FetchCalculateAction
{
    public function __invoke($zipcode, $details)
    {
        $url = config('services.vitau.url') . 'orders/calculate/';

        $response = Http::withHeaders([
            'x-api-key' => config('services.vitau.key'),
        ])->post($url, [
            'zipcode' => $zipcode,
            'details' => $details
        ]);


        if ($response->failed()) {
            throw new Exception();
        }

        return $response->json();
    }
}
