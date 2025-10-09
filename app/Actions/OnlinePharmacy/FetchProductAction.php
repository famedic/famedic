<?php

namespace App\Actions\OnlinePharmacy;

use Exception;
use Illuminate\Support\Facades\Http;

class FetchProductAction
{
    public function __invoke(string $productId): array
    {
        $url = config('services.vitau.url') . 'products/' . $productId;

        $response = Http::withHeaders([
            'x-api-key' => config('services.vitau.key'),
        ])->get($url);


        if ($response->failed()) {
            throw new Exception();
        }

        return $response->json();
    }
}
